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
     * Application contexts (PRD §6.1). A session is issued for exactly one.
     */
    public const CONTEXT_PLATFORM_PORTAL = 'platform_portal';

    public const CONTEXT_TENANT_WORKSPACE = 'tenant_workspace';

    /**
     * Authenticate a user and issue a Sanctum personal access token.
     *
     * `context` names the application the credentials were submitted to. The
     * role must be allowed there: platform_admin and tenant_admin only in the
     * Platform Portal; facility_admin, operator, staff and client only in the
     * Tenant Workspace. Omitting it (non-browser clients, legacy callers) keeps
     * the previous behaviour; the UI always sends it.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'context' => ['nullable', 'string', 'in:'.self::CONTEXT_PLATFORM_PORTAL.','.self::CONTEXT_TENANT_WORKSPACE],
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

        $context = $validated['context'] ?? null;

        if ($context !== null && ! $this->roleAllowedInContext($user, $context)) {
            return response()->json([
                'message' => 'Akun ini tidak dapat masuk pada aplikasi ini.',
                'error_type' => 'WRONG_APPLICATION_CONTEXT',
            ], 403);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $this->issueToken($user, $context),
                'context' => $context,
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
     *
     * The context is recorded as a token ability (`ctx:platform_portal` or
     * `ctx:tenant_workspace`) so `EnsureApplicationContext` can prove on every
     * request that the token was issued by this application. A token minted by
     * the other frontend is refused even when its user is otherwise valid.
     */
    private function issueToken(User $user, ?string $context = null): string
    {
        $ttlMinutes = (int) config('auth.token_ttl_minutes', 480);

        $abilities = ['*'];

        if ($context !== null) {
            $abilities = ["ctx:{$context}"];
        }

        return $user->createToken('web', $abilities, now()->addMinutes($ttlMinutes))->plainTextToken;
    }

    /**
     * Whether the role may sign in to the given application context.
     */
    private function roleAllowedInContext(User $user, string $context): bool
    {
        $role = $user->role;

        return match ($context) {
            self::CONTEXT_PLATFORM_PORTAL => in_array($role?->value, ['platform_admin', 'tenant_admin'], true),
            self::CONTEXT_TENANT_WORKSPACE => in_array($role?->value, ['facility_admin', 'operator', 'staff', 'client'], true),
            default => false,
        };
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
        $facilityQuery = $user->hasTenantWideAccess()
            ? ($user->tenant?->facilities()->where('is_active', true)->orderBy('id') ?? null)
            : null;

        $facilities = $facilityQuery !== null
            ? $facilityQuery->get(['id', 'name', 'code'])
            : \App\Models\Facility::query()
                ->whereIn('id', $user->facilityAssignments()->select('facility_id'))
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'name', 'code']);

        $facilityIds = $facilities->pluck('id')->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'tenant_id' => $user->tenant_id,
            // Names for the sidebar header + facility switcher, so a workspace
            // role does not need tenant-wide facility-list permission to label
            // its own facility (PRD §8.3, §9).
            'facilities' => $facilities->map(fn($facility): array => [
                'id' => $facility->id,
                'name' => $facility->name,
                'code' => $facility->code,
            ])->all(),
            'tenant' => $user->tenant === null ? null : [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
                'status' => $user->tenant->status?->value,
                'timezone' => $user->tenant->timezone,
            ],
            'facility_ids' => $facilityIds,
            'station_id' => $user->station_id,
            'profile' => $user->customer,
        ];
    }
}
