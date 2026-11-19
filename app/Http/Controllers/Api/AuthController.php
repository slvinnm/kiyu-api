<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
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
     * Register a public patient account and issue a Sanctum token.
     *
     * Registration is deliberately restricted to the patient role. Privileged
     * roles must be provisioned by an administrator, never by public signup.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'patient',
        ]);

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
     * Issue a time-limited Sanctum personal access token.
     */
    private function issueToken(User $user): string
    {
        $ttlMinutes = (int) env('AUTH_TOKEN_TTL_MINUTES', 480);

        return $user->createToken('web', ['*'], now()->addMinutes($ttlMinutes))->plainTextToken;
    }

    /**
     * Shape the user payload returned to API clients.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing(['patient']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'department_id' => $user->department_id,
            'station_id' => $user->station_id,
            'profile' => $user->patient,
        ];
    }
}
