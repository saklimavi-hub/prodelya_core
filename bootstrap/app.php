<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.tenant' => \App\Http\Middleware\ResolveTenant::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantIsActive::class,
            'module.enabled' => \App\Http\Middleware\EnsureModuleEnabled::class,
            'feature.enabled' => \App\Http\Middleware\EnsureFeatureEnabled::class,
            'permission.check' => \App\Http\Middleware\EnsureRolePermission::class,
            'central.access' => \App\Http\Middleware\EnsureCentralAccess::class,
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'tenant.membership' => \App\Http\Middleware\EnsureTenantUserBelongsToTenant::class,
            'customer.portal.auth' => \App\Http\Middleware\EnsureCustomerPortalAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
