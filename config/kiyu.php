<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Origins
    |--------------------------------------------------------------------------
    |
    | The API is served from one central host. The browser application context
    | is therefore identified by the validated frontend Origin, never by the
    | API host. Each origin is an exact scheme://host[:port] string.
    |
    */

    'origins' => [
        'public' => env('KIYU_PUBLIC_ORIGIN', 'http://kiyu.test'),
        'app' => env('KIYU_APP_ORIGIN', 'http://app.kiyu.test'),
        'portal' => env('KIYU_PORTAL_ORIGIN', 'http://portal.kiyu.test'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Origins
    |--------------------------------------------------------------------------
    |
    | Tenant workspaces live on one label before the configured suffix, for
    | example `acme.kiyu.test`. The pattern is anchored and matched against the
    | full origin. Reserved labels can never resolve as a tenant slug.
    |
    */

    'tenant_domain_suffix' => env('KIYU_TENANT_DOMAIN_SUFFIX', 'kiyu.test'),

    'tenant_origin_pattern' => env('KIYU_TENANT_ORIGIN_PATTERN'),

    'reserved_labels' => [
        'kiyu', 'app', 'api', 'www', 'admin', 'portal', 'support', 'docs',
        'status', 'static', 'assets',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Feature release and resolved entitlement lookups may be cached. Capacity
    | and quota decisions are never cached; they read the database inside a
    | transaction. A null TTL means the cache lives until it is invalidated.
    |
    */

    'cache' => [
        'prefix' => 'kiyu',
        'ttl_seconds' => env('KIYU_CACHE_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp grandfathering
    |--------------------------------------------------------------------------
    |
    | The quota granted to subscriptions that already had the old boolean
    | `whatsapp_notifications` toggle when the platform moved to quota add-ons.
    | Null denies until the product value is decided; never invent a number.
    |
    */

    'whatsapp_grandfather_quota' => env('KIYU_WHATSAPP_GRANDFATHER_QUOTA'),

];
