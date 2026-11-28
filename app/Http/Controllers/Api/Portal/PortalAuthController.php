<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\ApplicationContext;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\ContextTokenIssuer;
use App\Services\Auth\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Platform administration authentication.
 *
 * Only `platform_admin` accounts with no tenant may sign in. There is no
 * register/signup: platform accounts are provisioned internally.
 */
class PortalAuthController extends Controller
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

        $user = $this->auth->attempt($validated['email'], $validated['password'], ApplicationContext::Portal);

        if ($user === null) {
            return response()->json([
                'message' => 'Email atau password salah.',
                'error_type' => 'INVALID_CREDENTIALS',
            ], 422);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'token' => $this->tokens->issue($user, ApplicationContext::Portal),
                'context' => ApplicationContext::Portal->value,
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
