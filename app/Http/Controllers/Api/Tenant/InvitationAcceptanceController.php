<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redeem a tenant invitation.
 *
 * Guest endpoint, but bound to the tenant origin: the invitation's tenant must
 * match the origin the request came from, and the token is hashed, single-use
 * and expiring. The role comes from the invitation, never from the request.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
    ) {}

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->invitations->accept(
            token: $validated['token'],
            name: $validated['name'],
            password: $validated['password'],
        );

        return response()->json([
            'message' => 'Invitation accepted.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'tenant_id' => $user->tenant_id,
            ],
        ], 201);
    }
}
