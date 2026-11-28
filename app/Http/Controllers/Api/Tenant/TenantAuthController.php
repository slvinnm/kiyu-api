<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Enums\ApplicationContext;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\ContextTokenIssuer;
use App\Services\Auth\OriginContextResolver;
use App\Services\Auth\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Operational workspace authentication for a tenant.
 *
 * Only `facility_admin`, `operator` and `staff` may sign in, and only on their
 * own tenant origin: the tenant slug from the validated Origin must equal the
 * account's tenant. Self-registration does not exist here; invitation
 * acceptance is a separate, tenant-origin-bound endpoint.
 */
class TenantAuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly ContextTokenIssuer $tokens,
        private readonly UserPresenter $presenter,
        private readonly OriginContextResolver $origins,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $slug = $this->origins->tenantSlugForOrigin($request->headers->get('Origin'))
            ?? ($request->header('X-Tenant-Slug') ?: null);

        $user = $this->auth->attempt($validated['email'], $validated['password'], ApplicationContext::Tenant);

        if ($user === null) {
            return response()->json([
                'message' => 'Email atau password salah.',
                'error_type' => 'INVALID_CREDENTIALS',
            ], 422);
        }

        if ($slug === null || strcasecmp($slug, (string) $user->tenant?->slug) !== 0) {
            return response()->json([
                'message' => 'Akun ini tidak dapat masuk pada tenant ini.',
                'error_type' => 'WRONG_TENANT_ORIGIN',
            ], 403);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $this->tokens->issue($user, ApplicationContext::Tenant),
                'context' => ApplicationContext::Tenant->value,
                'user' => $this->presenter->present($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->presenter->present($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
