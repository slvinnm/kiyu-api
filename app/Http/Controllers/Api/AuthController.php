<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Authenticate a user and issue a Sanctum personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user
            || ! Hash::check($validated['password'], $user->password)
            || ! $this->accountCanAuthenticate($user)
        ) {
            return response()->json([
                'message' => 'Email atau password salah.',
                'error_type' => 'INVALID_CREDENTIALS',
            ], 422);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $this->issueToken($user),
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /**
     * Self-register a client account inside the resolved tenant.
     *
     * Registration is tenant-scoped (the tenant comes from the public
     * locator, never from the payload), only ever creates a `client` account,
     * and is refused when the tenant has not enabled public registration.
     * Privileged roles are created by provisioning or invitation, never here.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404, 'Tenant not specified.');

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
            'role' => UserRole::Client,
        ]);

        $user->tenant_id = $tenant->id;
        $user->save();

        return response()->json([
            'message' => 'Registration successful.',
            'data' => [
                'token' => $this->issueToken($user),
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->userPayload($user),
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Whether the account is allowed to sign in at all.
     *
     * A platform operator has no tenant; every other role must have one and
     * that tenant must be active.
     */
    private function accountCanAuthenticate(User $user): bool
    {
        if ($user->isPlatformUser()) {
            return $user->tenant_id === null;
        }

        if ($user->tenant_id === null) {
            return false;
        }

        return $user->tenant?->status === TenantStatus::Active;
    }

    /**
     * Issue a time-limited Sanctum personal access token.
     */
    private function issueToken(User $user): string
    {
        $ttlMinutes = (int) config('auth.token_ttl_minutes', 480);

        return $user->createToken('web', ['*'], now()->addMinutes($ttlMinutes))->plainTextToken;
    }

    /**
     * Shape the user payload returned to API clients.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing(['customer', 'tenant']);

        // Every facility the user may act at. A single entry is the implicit
        // context; several let the browser render a facility switcher.
        $facilityIds = $user->hasTenantWideAccess()
            ? $user->tenant?->facilities()->where('is_active', true)->orderBy('id')->pluck('id')->all() ?? []
            : $user->facilityAssignments()->pluck('facility_id')->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'tenant_id' => $user->tenant_id,
            'facility_ids' => $facilityIds,
            'station_id' => $user->station_id,
            'profile' => $user->customer,
        ];
    }
}
