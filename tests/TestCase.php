<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prefix → configured origin, used to simulate the browser that would send
     * a matching `Origin` header for a context. A test that needs a *wrong* or
     * *missing* origin overrides it explicitly with `withHeader('Origin', ...)`
     * (an empty value suppresses injection).
     */
    private const CONTEXT_ORIGINS = [
        'api/app/' => 'app',
        'api/portal/' => 'portal',
        'api/public/' => 'public',
    ];

    /**
     * Send the given context's configured Origin with every request.
     */
    protected function asContext(string $context): static
    {
        return $this->withHeader('Origin', (string) config("kiyu.origins.{$context}"));
    }

    /**
     * Send the tenant origin for a given tenant slug.
     */
    protected function withTenantOrigin(string $slug): static
    {
        $scheme = parse_url((string) config('kiyu.origins.app'), PHP_URL_SCHEME) ?: 'http';
        $suffix = (string) config('kiyu.tenant_domain_suffix', 'kiyu.test');

        return $this->withHeader('Origin', "{$scheme}://{$slug}.{$suffix}");
    }

    /**
     * Inject the Origin matching the URI's context when the test has not
     * already chosen one.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (! isset($this->defaultHeaders['Origin'])) {
            $origin = $this->originForUri($uri);

            if ($origin !== null) {
                $server['HTTP_ORIGIN'] = $origin;
            }
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * The Origin for a URI's context, or null when it cannot be inferred.
     *
     * Tenant and customer contexts are bound to the tenant subdomain; when the
     * test has authenticated a user, the Origin of that user's tenant is sent,
     * mirroring the browser. Tests that need a foreign or missing origin
     * override it with `withHeader('Origin', ...)`.
     */
    private function originForUri(string $uri): ?string
    {
        $path = ltrim((string) parse_url($uri, PHP_URL_PATH), '/');

        foreach (self::CONTEXT_ORIGINS as $prefix => $context) {
            if (str_starts_with($path, $prefix)) {
                return (string) config("kiyu.origins.{$context}");
            }
        }

        if (str_starts_with($path, 'api/tenant/') || str_starts_with($path, 'api/customer/')) {
            $user = auth()->user();
            $slug = $user?->tenant?->slug;

            if (is_string($slug) && $slug !== '') {
                $scheme = parse_url((string) config('kiyu.origins.app'), PHP_URL_SCHEME) ?: 'http';
                $suffix = (string) config('kiyu.tenant_domain_suffix', 'kiyu.test');

                return "{$scheme}://{$slug}.{$suffix}";
            }
        }

        return null;
    }
}
