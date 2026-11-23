<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more roles.
 *
 * Usage: `->middleware('role:admin,receptionist')`. The authenticated user's
 * stored role is authoritative; a platform role never satisfies a clinic-role
 * requirement unless it is named explicitly.
 */
class EnsureUserRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 403, 'Forbidden.');

        if ($roles === []) {
            return $next($request);
        }

        abort_unless(
            in_array($user->role?->value, $roles, true),
            403,
            'Forbidden.',
        );

        return $next($request);
    }
}
