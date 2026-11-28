<?php

namespace App\Http\Middleware;

use App\Enums\ApplicationContext;
use App\Models\User;
use App\Services\Auth\ContextTokenIssuer;
use App\Services\Auth\OriginContextResolver;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind a route group to exactly one frontend application.
 *
 * A protected request must prove its context by a *credential* signal, never by
 * a bare Origin:
 *
 *   - a personal access token must carry this context's `ctx:*` ability and no
 *     wildcard; or
 *   - a first-party/transient (session) token must arrive with a validated
 *     browser Origin that maps to this context.
 *
 * If both an Origin and a token ability are present they must agree. A missing
 * or ambiguous signal fails closed. Origin is a context selector, not
 * authentication; role, tenant membership, facility and policy checks run in
 * later middleware regardless.
 *
 * Usage: `->middleware('context:app')`.
 */
class EnsureApplicationContext
{
    public function __construct(
        private readonly OriginContextResolver $origins,
        private readonly ContextTokenIssuer $issuer,
    ) {}

    public function handle(Request $request, Closure $next, string $context): Response
    {
        $expected = ApplicationContext::tryFrom($context);

        abort_if($expected === null || $expected->tokenAbility() === null, 500, 'Unknown application context.');

        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, 401, 'Unauthenticated.');

        $originContext = $this->origins->resolve($request->headers->get('Origin'));
        $originProvided = $this->originProvided($request);

        // A customer lives on the tenant subdomain, so a Tenant origin is the
        // correct browser origin for the Customer context as well.
        $originSatisfies = fn(?ApplicationContext $resolved): bool => $resolved === $expected
            || ($expected === ApplicationContext::Customer && $resolved === ApplicationContext::Tenant);

        if ($originProvided && ! $originSatisfies($originContext)) {
            abort(403, 'This session does not belong to this application.');
        }

        $token = $user->currentAccessToken();

        if ($token === null || $token instanceof TransientToken) {
            // A first-party session/`actingAs` token has no ability list; its
            // only context proof is a validated browser Origin.
            abort_if(
                ! $originSatisfies($originContext),
                403,
                'This session does not belong to this application.',
            );

            $this->bind($request, $expected);

            return $next($request);
        }

        if (! $token instanceof PersonalAccessToken) {
            abort(403, 'This session does not belong to this application.');
        }

        $tokenContext = $this->issuer->contextForToken($token);

        abort_if($tokenContext === null, 403, 'This token is not scoped to an application.');

        abort_unless($tokenContext === $expected, 403, 'This session was issued for a different application.');

        $this->bind($request, $expected);

        return $next($request);
    }

    /**
     * Record the resolved context on the request for downstream middleware.
     */
    private function bind(Request $request, ApplicationContext $expected): void
    {
        $request->attributes->set('application_context', $expected);

        if ($expected === ApplicationContext::Tenant || $expected === ApplicationContext::Customer) {
            $request->attributes->set(
                'origin_tenant_slug',
                $this->origins->tenantSlugForOrigin($request->headers->get('Origin')),
            );
        }
    }

    /**
     * Whether the client actually supplied an Origin header.
     */
    private function originProvided(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return $origin !== null && trim($origin) !== '' && trim($origin) !== 'null';
    }
}
