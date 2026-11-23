<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-only plan catalogue management.
 */
class PlanController extends Controller
{
    /**
     * List plans with their prices and entitlements.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Plan::query()
                ->with(['prices', 'entitlements'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Create a plan with optional prices and entitlements.
     */
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
            'entitlements.*.feature_code' => ['required', 'string', 'max:100'],
            'entitlements.*.value' => ['nullable', 'array'],
        ]);

        $plan = Plan::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        foreach ($validated['prices'] ?? [] as $price) {
            $plan->prices()->create([
                'billing_interval' => $price['billing_interval'],
                'amount' => $price['amount'],
                'currency' => strtoupper($price['currency']),
                'provider' => '',
            ]);
        }

        foreach ($validated['entitlements'] ?? [] as $entitlement) {
            $plan->entitlements()->create([
                'feature_code' => $entitlement['feature_code'],
                'value' => $entitlement['value'] ?? [],
            ]);
        }

        return response()->json([
            'message' => 'Plan created successfully.',
            'data' => $plan->load(['prices', 'entitlements']),
        ], 201);
    }
}
