<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route group to the platform operator role.
 *
 * Platform access is granted by the explicitly assigned platform role, never
 * merely because an account has no tenant.
 */
class EnsurePlatformAccount
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless(
            $user !== null
                && $user->isPlatformUser()
                && $user->tenant_id === null,
            403,
            'Forbidden.',
        );

        return $next($request);
    }
}
