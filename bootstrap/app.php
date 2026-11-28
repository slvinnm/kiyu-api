<?php

use App\Http\Middleware\EnsureApplicationContext;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsureTenantOriginBinding;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\ResolveAppTenant;
use App\Http\Middleware\ResolveFacility;
use App\Http\Middleware\ResolveKioskDevice;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveTenantFromOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__ . '/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'tenant' => ResolveTenant::class,
            'app.tenant' => ResolveAppTenant::class,
            'tenant.origin' => ResolveTenantFromOrigin::class,
            'tenant.bind' => EnsureTenantOriginBinding::class,
            'facility' => ResolveFacility::class,
            'kiosk' => ResolveKioskDevice::class,
            'feature' => EnsurePlanFeature::class,
            'context' => EnsureApplicationContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
