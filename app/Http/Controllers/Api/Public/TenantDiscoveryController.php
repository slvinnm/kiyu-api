<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only tenant resolution.
 *
 * The frontend parses the hostname and asks this endpoint whether the label is
 * a real, publicly available tenant. Responses:
 *
 *   200 — exists and is `ACTIVE`; only public branding/context is returned.
 *   403 — exists but is suspended/pending/cancelled; the UI shows an
 *         availability page instead of a login form.
 *   404 — no such tenant; the UI renders a genuine not-found page.
 *
 * No authentication, no membership: this proves a tenant *exists*, never that
 * the caller belongs to it. Every protected endpoint re-checks membership.
 */
class TenantDiscoveryController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        abort_if($tenant === null, 404, 'Tenant not found.');

        if ($tenant->status !== TenantStatus::Active) {
            return response()->json([
                'message' => 'This tenant is not available.',
                'data' => [
                    'slug' => $tenant->slug,
                    'status' => $tenant->status?->value,
                ],
            ], 403);
        }

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status?->value,
                'timezone' => $tenant->timezone,
                'allow_public_registration' => (bool) $tenant->allow_public_registration,
            ],
        ]);
    }
}
