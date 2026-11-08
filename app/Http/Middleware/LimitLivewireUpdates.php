<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * A per-person ceiling on Livewire calls (POST /livewire/update): the bid panel polls every few seconds per open tab, the
 * bell every 30, and every click is a call, so this is the endpoint that scales with viewers. Livewire registers its own
 * route before the application's routes load and offers no hook for route middleware, so the limit is applied here, in the
 * `web` group (after the session starts), to that one path. The limit itself is the `livewire` rate limiter (AppServiceProvider).
 */
class LimitLivewireUpdates
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('livewire/update')) {
            return $next($request);
        }

        // The named limiter does the counting; ThrottleRequests turns "too many" into a 429 with Retry-After.
        return $this->throttle->handle($request, $next, 'livewire');
    }
}
