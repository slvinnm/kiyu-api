<?php

namespace App\Http\Controllers\Api\App\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\TenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Self-service SaaS signup (PRD §12.1).
 *
 * This lives on the Platform Portal, never behind tenant resolution: the person
 * has no Tenant yet. One request creates the Tenant, its canonical owner, its
 * first Facility, a default queue template, and a TRIALING subscription, all in
 * one transaction. It never creates a `platform_admin`.
 */
class SignupController extends Controller
{
    private const TRIAL_DAYS = 14;

    public function __construct(private readonly TenantOnboardingService $onboarding) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:63', 'alpha_dash', Rule::notIn($this->reservedLabels())],
            'timezone' => ['nullable', 'string', 'max:64'],
            'plan_code' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8'],
        ]);

        // The trial plan is the default when the caller names none.
        $planCode = $validated['plan_code'] ?? 'TRIAL';

        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->first();
        abort_if($plan === null, 422, 'The selected plan is not available.');

        $result = DB::transaction(fn(): array => $this->onboarding->provision([
            'name' => $validated['organization_name'],
            'slug' => $validated['slug'] ?? null,
            'timezone' => $validated['timezone'] ?? 'UTC',
            // The organization name doubles as the first facility name and
            // the owner is the admin; both are editable after onboarding.
            'facility_name' => $validated['organization_name'],
            'admin_name' => $validated['owner_name'],
            'admin_email' => $validated['owner_email'],
            'admin_password' => $validated['owner_password'],
            'plan_code' => $plan->code,
            'trial_days' => self::TRIAL_DAYS,
        ]));

        $admin = $result['tenant_admin'];

        return response()->json([
            'message' => 'Organization created. Your trial has started.',
            'data' => [
                'tenant' => [
                    'id' => $result['tenant']->id,
                    'name' => $result['tenant']->name,
                    'slug' => $result['tenant']->slug,
                    'status' => $result['tenant']->status?->value,
                ],
                'subscription' => $result['subscription'] === null ? null : [
                    'status' => $result['subscription']->status?->value,
                    'trial_ends_at' => $result['subscription']->trial_ends_at?->toIso8601String(),
                ],
                'owner' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ],
            ],
        ], 201);
    }

    /**
     * Reserved labels that can never be a tenant slug (PRD §3.3).
     *
     * @return list<string>
     */
    private function reservedLabels(): array
    {
        return [
            'kiyu', 'app', 'api', 'www', 'admin', 'support', 'docs',
            'status', 'static', 'assets',
        ];
    }
}
