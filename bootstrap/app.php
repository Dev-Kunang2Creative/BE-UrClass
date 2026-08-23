<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // This app sits behind Cloudflare. Without trusting the proxy,
        // $request->ip() returns a Cloudflare address for every visitor, which
        // collapses the throttle:5,1 limiters on login and register into a
        // single shared bucket - five attempts a minute would lock out
        // everyone - and sends the wrong remoteip to Turnstile's siteverify.
        //
        // Only Cloudflare's own ranges are trusted, not '*': the Hostinger
        // origin is still reachable by IP, so a wildcard would let anyone
        // forge X-Forwarded-For and walk straight past the rate limits.
        //
        // List from https://api.cloudflare.com/client/v4/ips
        // (etag 38f79d050aa027e3be3865e495dcc9bc). Refresh it if Cloudflare
        // publishes new ranges.
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
            '2c0f:f248::/32',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API-only backend and defines no route named "login".
        // Laravel's unauthenticated handler falls back to route('login')
        // whenever the request does not expect JSON, so hitting any /api/*
        // endpoint without an Accept: application/json header returned a 500
        // instead of a 401. Force JSON rendering for the whole API surface.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();