<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Runtime platform settings (PRD §10.8).
 *
 * Only the safe, non-secret settings are exposed: the public registration policy
 * and the reserved-subdomain list. Provider credentials are not editable here.
 */
class SettingController extends Controller
{
    /** Labels never usable as a tenant slug (PRD §3.3). */
    public const DEFAULT_RESERVED_LABELS = [
        'kiyu',
        'app',
        'api',
        'www',
        'admin',
        'support',
        'docs',
        'status',
        'static',
        'assets',
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'allow_public_signup' => $this->read('allow_public_signup', true),
                'reserved_subdomains' => $this->read('reserved_subdomains', self::DEFAULT_RESERVED_LABELS),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'allow_public_signup' => ['sometimes', 'boolean'],
            'reserved_subdomains' => ['sometimes', 'array'],
            'reserved_subdomains.*' => ['string', 'max:63', 'regex:/^[a-z0-9-]+$/'],
        ]);

        DB::transaction(function () use ($validated): void {
            if (array_key_exists('allow_public_signup', $validated)) {
                $this->write('allow_public_signup', (bool) $validated['allow_public_signup']);
            }

            if (array_key_exists('reserved_subdomains', $validated)) {
                $this->write('reserved_subdomains', array_values(array_unique($validated['reserved_subdomains'])));
            }
        });

        $this->audit->log(
            action: 'platform.settings.updated',
            entityType: 'platform_settings',
            entityId: 'global',
            metadata: $validated,
            platformUserId: $request->user()?->id,
            tenant: null,
        );

        return $this->show();
    }

    private function read(string $key, mixed $default): mixed
    {
        $row = DB::table('platform_settings')->where('key', $key)->first();

        if ($row === null) {
            return $default;
        }

        return json_decode((string) $row->value, true) ?? $default;
    }

    private function write(string $key, mixed $value): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
