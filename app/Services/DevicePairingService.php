<?php

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Concerns\HasDevicePairing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One pairing-code implementation for kiosk and display devices.
 *
 * Both device types follow the same rules, so the code generation, the
 * single-use redemption and the "cannot reissue once paired" guard live here
 * once instead of in two near-identical controllers.
 */
class DevicePairingService
{
    /** How long an operator has to enter the pairing code. */
    public const PAIRING_TTL_MINUTES = 30;

    /** Length of the generated pairing code. */
    public const PAIRING_CODE_LENGTH = 8;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Generate a short, unambiguous pairing code that is not already taken.
     *
     * The alphabet omits characters that are easy to confuse when read off a
     * screen (0/O, 1/I/L). A unique index backs the hash, so a (very unlikely)
     * collision is retried rather than failing the request.
     *
     * @param  class-string<Model&HasDevicePairing>  $model
     */
    public function generateCode(string $model): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::PAIRING_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (! $model::query()->where('pairing_code_hash', hash('sha256', $code))->exists()) {
                return $code;
            }
        }

        throw new InvalidStateException('Could not generate a unique pairing code; please retry.');
    }

    /**
     * Normalise operator input into a pairing code.
     */
    public function normaliseCode(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
    }

    /**
     * Trade a code for a long-lived credential, once.
     *
     * The update only succeeds while the stored code still matches, so two
     * devices racing on the same code cannot both complete.
     *
     * @param  class-string<Model&HasDevicePairing>  $model
     * @return array{device: Model, credential: string}
     */
    public function redeem(string $model, string $code, string $label): array
    {
        /** @var Model&HasDevicePairing|null $device */
        $device = $model::query()
            ->with(['tenant', 'facility'])
            ->where('pairing_code_hash', hash('sha256', $code))
            ->first();

        abort_if($device === null, 404, 'The pairing code is not recognised.');

        abort_unless($device->isAwaitingPairing(), 403, 'The pairing code has expired or was already used.');

        abort_unless(
            $device->tenant?->status->isOperational() ?? false,
            403,
            "The {$label} belongs to a tenant that is not active.",
        );

        abort_if(
            $device->facility === null || $device->facility->tenant_id !== $device->tenant_id,
            403,
            "The {$label} is not bound to a valid facility.",
        );

        $credential = bin2hex(random_bytes(32));

        $burned = $model::query()
            ->whereKey($device->getKey())
            ->where('pairing_code_hash', hash('sha256', $code))
            ->update([
                'credential_hash' => hash('sha256', $credential),
                'pairing_code_hash' => null,
                'pairing_expires_at' => null,
                'paired_at' => now(),
                'last_seen_at' => now(),
            ]);

        abort_if($burned === 0, 409, 'The pairing code was used by another device.');

        $device->refresh()->load(['tenant', 'facility']);

        return [
            'device' => $device,
            'credential' => $credential,
        ];
    }

    /**
     * Shape the post-pairing response both device types return.
     *
     * @return array<string, mixed>
     */
    public function pairedPayload(Model $device, string $credential): array
    {
        return [
            'credential' => $credential,
            'device' => [
                'id' => $device->getKey(),
                'name' => $device->name,
                'tenant' => [
                    'id' => $device->tenant->id,
                    'name' => $device->tenant->name,
                    'slug' => $device->tenant->slug,
                ],
                'facility' => [
                    'id' => $device->facility->id,
                    'name' => $device->facility->name,
                    'code' => $device->facility->code,
                ],
            ],
        ];
    }

    /**
     * A placeholder credential for a device that has not paired yet.
     */
    public function placeholderCredentialHash(): string
    {
        return hash('sha256', Str::random(64));
    }
}
