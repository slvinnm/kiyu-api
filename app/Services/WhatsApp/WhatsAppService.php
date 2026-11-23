<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use App\Enums\Feature;
use App\Enums\WhatsAppMessageType;
use App\Models\Tenant;
use App\Services\SubscriptionService;

/**
 * Sends WhatsApp messages through the configured gateway, gated by the
 * tenant's plan entitlement.
 */
class WhatsAppService
{
    public function __construct(
        private readonly WhatsAppGateway $gateway,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Whether WhatsApp is enabled globally and granted to the tenant.
     */
    public function isAvailableFor(Tenant $tenant): bool
    {
        $enabled = (bool) config('services.whatsapp.enabled', false);

        return $enabled
            && $this->gateway->isConfigured()
            && $this->subscriptions->hasFeature($tenant, Feature::WhatsAppNotifications);
    }

    /**
     * Send a text message.
     *
     * @return array<string, mixed>|null Null when WhatsApp is unavailable.
     */
    public function sendText(Tenant $tenant, string $to, string $body): ?array
    {
        return $this->dispatch($tenant, [
            'type' => WhatsAppMessageType::Text,
            'to' => $to,
            'body' => $body,
        ]);
    }

    /**
     * Send a media message (image, video or document).
     *
     * @return array<string, mixed>|null
     */
    public function sendMedia(
        Tenant $tenant,
        string $to,
        WhatsAppMessageType $type,
        string $mediaUrl,
        ?string $caption = null,
        ?string $filename = null,
    ): ?array {
        return $this->dispatch($tenant, [
            'type' => $type,
            'to' => $to,
            'body' => $caption,
            'media_url' => $mediaUrl,
            'filename' => $filename,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function dispatch(Tenant $tenant, array $message): ?array
    {
        if (! $this->isAvailableFor($tenant)) {
            return null;
        }

        return $this->gateway->send($message);
    }
}
