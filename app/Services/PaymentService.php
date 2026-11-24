<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidStateException;
use App\Models\BillingWebhookEvent;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Starts subscription charges and applies provider webhooks.
 *
 * Webhooks are idempotent: each provider event id is stored once, so a
 * retried delivery cannot double-apply. Nothing trusts the payload until the
 * gateway has verified its signature.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Start a charge for the tenant's effective subscription.
     *
     * @return array<string, mixed> The client-facing payload.
     */
    public function startCharge(Tenant $tenant): array
    {
        if (! $this->gateway->isConfigured()) {
            throw new InvalidStateException('No payment gateway is configured.');
        }

        $subscription = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['TRIALING', 'ACTIVE', 'PAST_DUE'])
            ->latest('id')
            ->first();

        if ($subscription === null) {
            throw new InvalidStateException('The tenant has no subscription to charge.');
        }

        $price = $subscription->planPrice;

        if ($price === null) {
            throw new InvalidStateException('The subscription has no price to charge.');
        }

        $charge = $this->gateway->createCharge($subscription, $price->amount, $price->currency);

        Payment::query()->create([
            'subscription_id' => $subscription->id,
            'provider' => $this->gateway->name(),
            'provider_transaction_id' => $charge['transaction_id'],
            'amount' => $price->amount,
            'currency' => $price->currency,
            'status' => PaymentStatus::Pending,
            'metadata' => [
                'redirect_url' => $charge['redirect_url'],
                'token' => $charge['token'],
            ],
        ]);

        return $charge;
    }

    /**
     * Apply a verified webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(string $provider, array $payload): void
    {
        if (! $this->gateway->verifySignature($payload)) {
            throw new InvalidStateException('Webhook signature verification failed.');
        }

        // Dedupe on the order and the status it reports, not on the provider's
        // transaction id. A retried settlement carries the same order_id and
        // status, while a genuine transition (pending -> settlement) is a
        // different event and must still be applied. Keying on transaction_id
        // let a retry that omitted it slip through as a second event.
        $orderId = (string) ($payload['order_id'] ?? '');
        $status = (string) ($payload['transaction_status'] ?? '');

        if ($orderId === '' || $status === '') {
            throw new InvalidStateException('Webhook payload is missing an order id or transaction status.');
        }

        $eventId = $orderId . ':' . $status;

        DB::transaction(function () use ($provider, $payload, $status, $eventId): void {
            // Idempotency: the unique (provider, event_id) makes a replay a no-op.
            $event = BillingWebhookEvent::query()->firstOrCreate(
                ['provider' => $provider, 'event_id' => $eventId],
                [
                    'event_type' => $status,
                    'payload' => $payload,
                    'status' => 'RECEIVED',
                    'received_at' => Carbon::now(),
                ],
            );

            if ($event->status === 'PROCESSED') {
                return;
            }

            try {
                $this->applyPayment($provider, $payload);
                $event->update(['status' => 'PROCESSED', 'processed_at' => Carbon::now()]);
            } catch (\Throwable $e) {
                $event->update(['status' => 'FAILED', 'error' => $e->getMessage()]);

                throw $e;
            }
        });
    }

    /**
     * Update the payment row and activate the subscription on success.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyPayment(string $provider, array $payload): void
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $status = PaymentStatus::fromMidtrans((string) ($payload['transaction_status'] ?? ''));

        $payment = Payment::query()
            ->where('provider', $provider)
            ->where('provider_transaction_id', $orderId)
            ->first();

        if ($payment === null) {
            return;
        }

        $payment->update([
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? Carbon::now() : $payment->paid_at,
        ]);

        if ($status !== PaymentStatus::Paid) {
            return;
        }

        $subscription = $payment->subscription;

        if ($subscription === null) {
            return;
        }

        // A paid charge activates the subscription for its period.
        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::now(),
            'current_period_end' => Carbon::now()->addMonths($subscription->billing_interval->months()),
        ]);
    }
}
