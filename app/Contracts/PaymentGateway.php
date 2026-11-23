<?php

namespace App\Contracts;

use App\Models\Subscription;

/**
 * A payment provider adapter.
 *
 * Callers never depend on a specific provider: a Midtrans, Stripe or manual
 * implementation all satisfy this contract. The application only needs to (1)
 * start a charge and (2) verify and interpret webhooks.
 */
interface PaymentGateway
{
    /**
     * The provider identifier stored on payments and webhook events.
     */
    public function name(): string;

    /**
     * Whether the provider is configured and usable.
     */
    public function isConfigured(): bool;

    /**
     * Create a payment/charge and return the client-facing payload (e.g. a
     * redirect URL or token) plus the provider transaction id.
     *
     * @return array{transaction_id: string, redirect_url: ?string, token: ?string, raw: array<string, mixed>}
     */
    public function createCharge(Subscription $subscription, int $amount, string $currency): array;

    /**
     * Verify an incoming webhook signature for the raw request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): bool;
}
