<?php

namespace App\Http\Controllers\Api;

use App\Enums\WhatsAppMessageType;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Send WhatsApp messages through the configured gateway. Gated by the
 * whatsapp_notifications entitlement (enforced in routes).
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * Send a text or media message.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:30'],
            'type' => ['required', Rule::enum(WhatsAppMessageType::class)],
            'body' => ['nullable', 'string', 'max:4096'],
            'media_url' => ['required_unless:type,text', 'nullable', 'url', 'max:2048'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $this->tenant();
        $type = WhatsAppMessageType::from($validated['type']);

        $response = $type->hasMedia()
            ? $this->whatsapp->sendMedia(
                tenant: $tenant,
                to: $validated['to'],
                type: $type,
                mediaUrl: $validated['media_url'],
                caption: $validated['body'] ?? null,
                filename: $validated['filename'] ?? null,
            )
            : $this->whatsapp->sendText($tenant, $validated['to'], (string) ($validated['body'] ?? ''));

        if ($response === null) {
            return response()->json([
                'message' => 'WhatsApp is not enabled for this tenant.',
                'error_type' => 'WHATSAPP_UNAVAILABLE',
            ], 409);
        }

        return response()->json([
            'message' => 'Message sent.',
            'data' => $response,
        ]);
    }
}
