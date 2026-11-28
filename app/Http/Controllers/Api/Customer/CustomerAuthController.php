<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\ApplicationContext;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\ContextTokenIssuer;
use App\Services\Auth\OriginContextResolver;
use App\Services\Auth\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Customer self-service authentication.
 *
 * Customers belong to exactly one tenant and are bound to its origin. They can
 * only ever reach their own profile, own visits and own bookings; the Customer
 * context is a fifth, self-service context alongside the four application
 * groups. Registration only ever creates a `customer` account, and only when
 * the tenant has opted into public registration.
 */
class CustomerAuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly ContextTokenIssuer $tokens,
        private readonly UserPresenter $presenter,
        private readonly OriginContextResolver $origins,
    ) {}

    /**
     * Register a customer account inside the origin tenant.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenant = $this->tenantFromOrigin($request);

        abort_unless(
            $tenant->allow_public_registration,
            403,
            'This tenant does not allow public registration.',
        );

        abort_unless(
            $tenant->status === TenantStatus::Active,
            403,
            'This tenant is not active.',
        );

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => UserRole::Customer,
        ]);

        $user->tenant_id = $tenant->id;
        $user->save();

        return response()->json([
            'message' => 'Registration successful.',
            'data' => [
                'token' => $this->tokens->issue($user, ApplicationContext::Customer),
                'context' => ApplicationContext::Customer->value,
                'user' => $this->presenter->present($user),
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $this->tenantFromOrigin($request);

        $user = $this->auth->attempt($validated['email'], $validated['password'], ApplicationContext::Customer);

        if ($user === null || $user->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'Email atau password salah.',
                'error_type' => 'INVALID_CREDENTIALS',
            ], 422);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $this->tokens->issue($user, ApplicationContext::Customer),
                'context' => ApplicationContext::Customer->value,
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

    /**
     * The tenant the request's origin belongs to.
     */
    private function tenantFromOrigin(Request $request): Tenant
    {
        $slug = $this->origins->tenantSlugForOrigin($request->headers->get('Origin'))
            ?? ($request->header('X-Tenant-Slug') ?: null);

        abort_if($slug === null, 404, 'Tenant not specified.');

        $tenant = Tenant::query()->where('slug', $slug)->first();

        abort_if($tenant === null, 404, 'Tenant not found.');

        return $tenant;
    }
}
