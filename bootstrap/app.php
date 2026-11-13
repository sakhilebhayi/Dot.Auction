<?php

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\KeepFlashForLivewire;
use App\Http\Middleware\LimitLivewireUpdates;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustedHostList;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);

        // A Livewire poll between a redirect and its page must not eat the flash message (see the class).
        $middleware->web(append: [KeepFlashForLivewire::class]);

        // Baseline browser protections (enforced headers plus a report-only CSP) on every response.
        $middleware->append(SecurityHeaders::class);

        // A per-person ceiling on Livewire calls (the bid panel's poll, the bell, clicks). In the web group, after the session
        // starts, so it can count per signed-in person rather than per address.
        $middleware->web(append: [LimitLivewireUpdates::class]);

        // R-50: the signed-in area closes when the person's current company no longer holds this platform in InfoDot. In the
        // web group (after the session starts); it leaves signed-out pages, sign-in, the hand-off, webhooks and /api alone.
        $middleware->web(append: [EnsureCompanyEntitled::class]);

        // Pin the Host header to the production host(s) (config security.trusted_hosts, default the APP_URL host).
        $middleware->trustHosts(at: fn () => TrustedHostList::patterns(), subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
