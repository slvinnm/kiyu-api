<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform plan catalogue management (PRD §10.5).
 *
 * A plan's prices and entitlements are editable, but a price is never mutated
 * once payments reference it: a change deactivates the old row and inserts a new
 * one, so historical payment amounts keep their meaning.
 */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Plan::query()
                ->with(['prices', 'entitlements'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => Plan::query()
                ->with(['prices', 'entitlements'])
                ->findOrFail($id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*.billing_interval' => ['required', Rule::in(['MONTHLY', 'YEARLY'])],
            'prices.*.amount' => ['required', 'integer', 'min:0'],
            'prices.*.currency' => ['required', 'string', 'size:3'],
            'entitlements' => ['nullable', 'array'],
            'entitlements.*.feature_code' => ['required', 'string', Rule::in(Feature::allValues())],
            'entitlements.*.limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $plan = DB::transaction(function () use ($validated): Plan {
            $plan = Plan::query()->create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->writePrices($plan, $validated['prices'] ?? []);
            $this->writeEntitlements($plan, $validated['entitlements'] ?? []);

            return $plan;
        });

        return response()->json([
            'message' => 'Plan created successfully.',
            'data' => $plan->load(['prices', 'entitlements']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan updated.',
            'data' => $plan->load(['prices', 'entitlements']),
        ]);
    }

    /**
     * Add a price to a plan. An existing row for the same interval/currency/
     * provider is deactivated (never deleted) so payments keep their meaning.
     */
    public function addPrice(Request $request, int $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);

        $validated = $request->validate([
            'billing_interval' => ['required', Rule::in(['MONTHLY', 'YEARLY'])],
            'amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['nullable', 'string', 'max:50'],
        ]);

        $price = DB::transaction(function () use ($plan, $validated): PlanPrice {
            PlanPrice::query()
                ->where('plan_id', $plan->id)
                ->where('billing_interval', $validated['billing_interval'])
                ->where('currency', strtoupper($validated['currency']))
                ->where('provider', $validated['provider'] ?? '')
                ->update(['is_active' => false]);

            return $plan->prices()->create([
                'billing_interval' => $validated['billing_interval'],
                'amount' => $validated['amount'],
                'currency' => strtoupper($validated['currency']),
                'provider' => $validated['provider'] ?? '',
                'is_active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Price added.',
            'data' => $price,
        ], 201);
    }

    /**
     * Replace a plan's entitlements.
     */
    public function updateEntitlements(Request $request, int $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);

        $validated = $request->validate([
            'entitlements' => ['required', 'array'],
            'entitlements.*.feature_code' => ['required', 'string', Rule::in(Feature::allValues())],
            'entitlements.*.limit' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($plan, $validated): void {
            $plan->entitlements()->delete();
            $this->writeEntitlements($plan, $validated['entitlements']);
        });

        return response()->json([
            'message' => 'Entitlements updated.',
            'data' => $plan->fresh(['prices', 'entitlements']),
        ]);
    }

    /**
     * @param  list<array{billing_interval: string, amount: int, currency: string}>  $prices
     */
    private function writePrices(Plan $plan, array $prices): void
    {
        foreach ($prices as $price) {
            $plan->prices()->create([
                'billing_interval' => $price['billing_interval'],
                'amount' => $price['amount'],
                'currency' => strtoupper($price['currency']),
                'provider' => '',
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  list<array{feature_code: string, limit?: int|null}>  $entitlements
     */
    private function writeEntitlements(Plan $plan, array $entitlements): void
    {
        foreach ($entitlements as $entitlement) {
            $feature = Feature::from($entitlement['feature_code']);

            $plan->entitlements()->create([
                'feature_code' => $feature->value,
                'value' => $feature->isLimit()
                    ? ['limit' => (int) ($entitlement['limit'] ?? 0)]
                    : null,
            ]);
        }
    }
}
