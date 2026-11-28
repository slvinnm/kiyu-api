<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform add-on catalogue management (PRD §13.2).
 *
 * Prices are versioned: a change deactivates the old row and inserts a new one,
 * so a historical payment keeps its meaning. Entitlement and eligibility edits
 * apply to future purchases only; existing subscription snapshots are unaffected.
 */
class AddonController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Addon::query()
                ->with(['prices', 'entitlements', 'eligibilities'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => Addon::query()
                ->with(['prices', 'entitlements', 'eligibilities'])
                ->findOrFail($id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:addons,code'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'entitlements' => ['nullable', 'array'],
            'entitlements.*.feature_code' => ['required', 'string', Rule::in(Feature::allValues())],
            'entitlements.*.limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $addon = DB::transaction(function () use ($validated): Addon {
            $addon = Addon::query()->create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            $this->writeEntitlements($addon, $validated['entitlements'] ?? []);

            return $addon;
        });

        $this->audit->log(
            action: 'platform.addon.created',
            entityType: 'addon',
            entityId: $addon->id,
            metadata: ['code' => $addon->code],
            platformUserId: $request->user()?->id,
            tenant: null,
        );

        return response()->json([
            'message' => 'Add-on created successfully.',
            'data' => $addon->load(['prices', 'entitlements']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $addon = Addon::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $addon->update($validated);

        return response()->json([
            'message' => 'Add-on updated.',
            'data' => $addon->load(['prices', 'entitlements']),
        ]);
    }

    /**
     * Add a price version. The previous active row for the same key is
     * deactivated (never mutated) so payments keep their meaning.
     */
    public function addPrice(Request $request, int $id): JsonResponse
    {
        $addon = Addon::query()->findOrFail($id);

        $validated = $request->validate([
            'billing_interval' => ['required', Rule::in(['MONTHLY', 'YEARLY'])],
            'amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['nullable', 'string', 'max:50'],
        ]);

        $price = DB::transaction(function () use ($addon, $validated) {
            $addon->prices()
                ->where('billing_interval', $validated['billing_interval'])
                ->where('currency', strtoupper($validated['currency']))
                ->where('provider', $validated['provider'] ?? '')
                ->update(['is_active' => false]);

            return $addon->prices()->create([
                'billing_interval' => $validated['billing_interval'],
                'amount' => $validated['amount'],
                'currency' => strtoupper($validated['currency']),
                'provider' => $validated['provider'] ?? '',
                'is_active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Add-on price added.',
            'data' => $price,
        ], 201);
    }

    /**
     * Replace the add-on's catalogue entitlements (future purchases only).
     */
    public function updateEntitlements(Request $request, int $id): JsonResponse
    {
        $addon = Addon::query()->findOrFail($id);

        $validated = $request->validate([
            'entitlements' => ['required', 'array'],
            'entitlements.*.feature_code' => ['required', 'string', Rule::in(Feature::allValues())],
            'entitlements.*.limit' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($addon, $validated): void {
            $addon->entitlements()->delete();
            $this->writeEntitlements($addon, $validated['entitlements']);
        });

        return response()->json([
            'message' => 'Add-on entitlements updated.',
            'data' => $addon->fresh(['prices', 'entitlements']),
        ]);
    }

    /**
     * Replace the set of plans eligible to buy this add-on.
     */
    public function updateEligibilities(Request $request, int $id): JsonResponse
    {
        $addon = Addon::query()->findOrFail($id);

        $validated = $request->validate([
            'plan_ids' => ['required', 'array'],
            'plan_ids.*' => ['required', 'integer', Rule::exists('plans', 'id')],
        ]);

        DB::transaction(function () use ($addon, $validated): void {
            $addon->eligibilities()->delete();

            foreach (array_unique($validated['plan_ids']) as $planId) {
                $addon->eligibilities()->create(['plan_id' => (int) $planId]);
            }
        });

        return response()->json([
            'message' => 'Add-on eligibility updated.',
            'data' => $addon->fresh(['eligibilities']),
        ]);
    }

    /**
     * @param  list<array{feature_code: string, limit?: int|null}>  $entitlements
     */
    private function writeEntitlements(Addon $addon, array $entitlements): void
    {
        foreach ($entitlements as $entitlement) {
            $feature = Feature::from($entitlement['feature_code']);
            $value = $feature->isToggle() || ! isset($entitlement['limit'])
                ? null
                : ['limit' => (int) $entitlement['limit']];

            $addon->entitlements()->updateOrCreate(
                ['feature_code' => $feature->value],
                ['value' => $value],
            );
        }
    }
}
