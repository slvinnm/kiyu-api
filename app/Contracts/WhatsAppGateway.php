<?php

namespace App\Contracts;

use App\Enums\WhatsAppMessageType;

/**
 * A WhatsApp provider adapter.
 *
 * Implementations translate a provider-neutral message into whatever the
 * third-party API expects. Callers never depend on a specific provider.
 */
interface WhatsAppGateway
{
    /**
     * Send a message and return the provider's response payload.
     *
     * @param  array{type: WhatsAppMessageType, to: string, body?: string|null, media_url?: string|null, filename?: string|null}  $message
     * @return array<string, mixed>
     */
    public function send(array $message): array;

    /**
     * Whether the gateway is configured and usable.
     */
    public function isConfigured(): bool;
}
