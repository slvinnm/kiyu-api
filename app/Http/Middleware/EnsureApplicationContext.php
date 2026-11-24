<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind a route group to one frontend application.
 *
 * A session is issued for exactly one context (`platform_portal` on
 * `app.kiyu.test`, `tenant_workspace` on `{tenant}.kiyu.test`). The context is
 * recorded as a token ability so a token minted by the other frontend is
 * refused here even when its user is valid. Role↔context legality is checked at
 * login; this middleware enforces the token half of the same rule on every
 * protected request.
 *
 * Usage: `->middleware('context:platform_portal')`.
 */
class EnsureApplicationContext
{
    public function handle(Request $request, Closure $next, string $context): Response
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Unauthenticated.');

        $token = $user->currentAccessToken();

        // Test/transient tokens and first-party session tokens carry no context
        // claim; the role checks still apply to them.
        if ($token === null || $token instanceof TransientToken) {
            return $next($request);
        }

        // Sanctum's own ability check honours the wildcard, so a machine or
        // legacy token (`['*']`) keeps working while a token minted by the other
        // frontend (`ctx:...`) is refused.
        abort_unless(
            $token->can("ctx:{$context}"),
            403,
            'This session was issued for a different application.',
        );

        return $next($request);
    }
}
