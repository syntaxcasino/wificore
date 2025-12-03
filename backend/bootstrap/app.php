<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up'
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register custom middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'user.active' => \App\Http\Middleware\CheckUserActive::class,
            'tenant.context' => \App\Http\Middleware\SetTenantContext::class,
            'system.admin' => \App\Http\Middleware\CheckSystemAdmin::class,
            'throttle.custom' => \App\Http\Middleware\ThrottleRequests::class,
            'ddos.protection' => \App\Http\Middleware\DDoSProtection::class,
            'subdomain.binding' => \App\Http\Middleware\EnforceSubdomainTenantBinding::class,
        ]);
        
        // Apply DDoS protection and subdomain binding globally to API routes
        $middleware->api(prepend: [
            \App\Http\Middleware\DDoSProtection::class,
            \App\Http\Middleware\EnforceSubdomainTenantBinding::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
