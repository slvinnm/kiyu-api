<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\ApplicationContext;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\ContextTokenIssuer;
use App\Services\Auth\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * TenantAdmin authentication for the customer SaaS application.
 *
 * Only `tenant_admin` accounts may sign in here. The tenant is always the one
 * linked to the authenticated account; the App origin is not tenant-specific.
 */
class AppAuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly ContextTokenIssuer $tokens,
        private readonly UserPresenter $presenter,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->auth->attempt($validated['email'], $validated['password'], ApplicationContext::App);

        if ($user === null) {
            return response()->json([
                'message' => 'Email atau password salah.',
                'error_type' => 'INVALID_CREDENTIALS',
            ], 422);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $this->tokens->issue($user, ApplicationContext::App),
                'context' => ApplicationContext::App->value,
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
