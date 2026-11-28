<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\Feature as FeatureCode;
use App\Http\Controllers\Controller;
use App\Models\Feature as FeatureRecord;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-only feature registry and global release switch.
 *
 * Listing shows every registered code and its release state. Toggling
 * `is_feature_released` is audited; `false` denies the feature in every tenant
 * and context without deleting entitlements, data or history.
 */
class FeatureController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => FeatureRecord::query()->orderBy('category')->orderBy('code')->get(),
        ]);
    }

    public function release(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'is_feature_released' => ['required', 'boolean'],
            'release_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $feature = FeatureCode::tryFrom($code);

        abort_if($feature === null, 404, 'Unknown feature code.');

        $record = FeatureRecord::query()->firstOrCreate(
            ['code' => $feature->value],
            [
                'name' => ucwords(str_replace('_', ' ', $feature->value)),
                'category' => $feature->category()->value,
                'value_type' => $feature->valueType()->value,
                'is_feature_released' => true,
            ],
        );

        $released = (bool) $validated['is_feature_released'];

        $record->update([
            'is_feature_released' => $released,
            'release_note' => $validated['release_note'] ?? $record->release_note,
            'released_at' => $released ? ($record->released_at ?? now()) : $record->released_at,
        ]);

        $this->audit->log(
            action: 'platform.feature.release_changed',
            entityType: 'feature',
            entityId: $record->id,
            metadata: ['code' => $record->code, 'is_feature_released' => $released],
            platformUserId: $request->user()?->id,
            tenant: null,
        );

        return response()->json([
            'message' => 'Feature release updated.',
            'data' => $record->fresh(),
        ]);
    }
}
