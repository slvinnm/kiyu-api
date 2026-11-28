<?php

namespace App\Services\Auth;

use App\Enums\ApplicationContext;

/**
 * Resolves the frontend application context of a request.
 *
 * The API is served from a central host, so `Request::getHost()` is the API
 * host, not the frontend. Context therefore comes from one of two signals:
 *
 *   1. a validated `Origin` header (browser requests), mapped to exactly one
 *      context; or
 *   2. for non-browser clients, the `ctx:*` ability of a scoped token, supplied
 *      by the caller of {@see self::contextForAbility()}.
 *
 * An `Origin` is a context *selector*, never a credential. Callers must still
 * authenticate the actor and verify tenant membership.
 */
class OriginContextResolver
{
    /**
     * Map a browser Origin to a context, or null when it is not recognised.
     *
     * Returns null for an absent/opaque Origin, a reserved label, a multi-label
     * host, or any origin that matches none of the configured frontends.
     */
    public function resolve(?string $origin): ?ApplicationContext
    {
        $normalised = $this->normalise($origin);

        if ($normalised === null) {
            return null;
        }

        $origins = (array) config('kiyu.origins', []);

        $exact = [
            [ApplicationContext::Public, 'public'],
            [ApplicationContext::App, 'app'],
            [ApplicationContext::Portal, 'portal'],
        ];

        foreach ($exact as [$context, $key]) {
            if (isset($origins[$key]) && $normalised === $this->normalise((string) $origins[$key])) {
                return $context;
            }
        }

        if ($this->tenantSlugForOrigin($normalised) !== null) {
            return ApplicationContext::Tenant;
        }

        return null;
    }

    /**
     * Extract the tenant slug from a validated tenant Origin, or null.
     *
     * The origin must be a single label followed by the configured suffix
     * (`acme.kiyu.test`). Labels that are reserved (app, portal, api, ...) are
     * rejected so they can never resolve as a tenant.
     */
    public function tenantSlugForOrigin(?string $origin): ?string
    {
        $normalised = $this->normalise($origin);

        if ($normalised === null) {
            return null;
        }

        $host = parse_url($normalised, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $pattern = (string) config('kiyu.tenant_origin_pattern');

        if ($pattern !== '') {
            $matched = preg_match($pattern, $normalised, $matches);

            if ($matched !== 1 || empty($matches['slug'])) {
                return null;
            }

            return $this->isReserved($matches['slug']) ? null : $matches['slug'];
        }

        $suffix = (string) config('kiyu.tenant_domain_suffix', 'kiyu.test');

        if (! str_ends_with($host, '.' . $suffix)) {
            return null;
        }

        $label = substr($host, 0, -(strlen($suffix) + 1));

        // Exactly one label: a nested host is never a tenant origin.
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return $this->isReserved($label) ? null : $label;
    }

    /**
     * Normalise an origin to a comparable scheme://host[:port] string.
     */
    private function normalise(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }

        $origin = rtrim(trim($origin), '/');

        if ($origin === '' || $origin === 'null') {
            return null;
        }

        $parts = parse_url($origin);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Whether the label is reserved and can never be a tenant slug.
     */
    private function isReserved(string $label): bool
    {
        return in_array(strtolower($label), (array) config('kiyu.reserved_labels', []), true);
    }
}
