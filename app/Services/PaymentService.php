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
use App\Services\Billing\AddonPurchaseService;
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
            ->whereIn('status', SubscriptionStatus::effectiveValues())
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

        $payment = Payment::query()->create([
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

        // Line item for the plan price, with a snapshot so a later catalogue edit
        // cannot rewrite this payment.
        $payment->items()->create([
            'plan_price_id' => $price->id,
            'name_snapshot' => $subscription->plan->name . ' (' . $subscription->billing_interval->value . ')',
            'unit_amount' => $price->amount,
            'quantity' => 1,
            'total_amount' => $price->amount,
            'currency' => $price->currency,
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
     * Activation snapshots the plan entitlements and supersedes the tenant's
     * previous granting subscription, all inside the webhook transaction.
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

        app(SubscriptionService::class)->activate($subscription);

        // Activate any add-ons bought in the same checkout and snapshot grants.
        $addons = app(AddonPurchaseService::class);

        $subscription->addons()
            ->where('status', 'PENDING_PAYMENT')
            ->get()
            ->each(fn($addon) => $addons->activate($addon));
    }
}
