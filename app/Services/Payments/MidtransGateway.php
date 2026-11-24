<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\InvalidStateException;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;

/**
 * Midtrans Snap payment gateway.
 *
 * Charges are created server-side against the Snap API; webhooks are verified
 * with Midtrans' SHA-512 signature over order_id + status_code +
 * gross_amount + server key. The webhook body is treated as untrusted until
 * the signature matches.
 */
class MidtransGateway implements PaymentGateway
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'midtrans';
    }

    /**
     * {@inheritDoc}
     */
    public function isConfigured(): bool
    {
        return $this->serverKey() !== '';
    }

    /**
     * {@inheritDoc}
     */
    public function createCharge(Subscription $subscription, int $amount, string $currency): array
    {
        $orderId = $this->orderId($subscription);

        $response = Http::withBasicAuth($this->serverKey(), '')
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->post($this->snapBase() . '/transactions', [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $amount,
                ],
                'customer_details' => [
                    'email' => $subscription->tenant?->billing_email,
                ],
            ]);

        if ($response->failed()) {
            throw new InvalidStateException(
                'Midtrans charge failed: ' . ($response->json('status_message') ?? $response->body()),
            );
        }

        return [
            'transaction_id' => $orderId,
            'redirect_url' => $response->json('redirect_url'),
            'token' => $response->json('token'),
            'raw' => $response->json() ?? [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function verifySignature(array $payload): bool
    {
        $signature = (string) ($payload['signature_key'] ?? '');

        if ($signature === '' || $this->serverKey() === '') {
            return false;
        }

        $expected = hash(
            'sha512',
            ($payload['order_id'] ?? '')
            . ($payload['status_code'] ?? '')
            . ($payload['gross_amount'] ?? '')
            . $this->serverKey(),
        );

        return hash_equals($expected, $signature);
    }

    /**
     * The order id for a subscription charge attempt.
     *
     * Sequential per subscription instead of time-based: two charges started
     * in the same second must not collide on the unique
     * (provider, provider_transaction_id) index, and the counter makes the
     * attempt number recoverable from the row itself.
     */
    public function orderId(Subscription $subscription): string
    {
        $attempt = Payment::query()
            ->where('subscription_id', $subscription->id)
            ->count() + 1;

        return sprintf('SUB-%d-%03d', $subscription->id, $attempt);
    }

    /**
     * The server key from config.
     */
    private function serverKey(): string
    {
        return (string) ($this->config['server_key'] ?? '');
    }

    /**
     * The Snap base URL (sandbox or production).
     */
    private function snapBase(): string
    {
        return rtrim((string) ($this->config['snap_base'] ?? 'https://app.sandbox.midtrans.com/snap/v1'), '/');
    }
}
