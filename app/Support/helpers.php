<?php

use App\Support\Display;

if (! function_exists('auction_route')) {
    /**
     * URL for a named route, or '#' when the route is not registered yet. The shell links the buyer pages through this
     * so it renders before those routes exist: `buyer.bids`, `buyer.watchlist`, `buyer.won`.
     */
    function auction_route(string $name, mixed $parameters = []): string
    {
        return Display::route($name, $parameters);
    }
}

if (! function_exists('auction_route_exists')) {
    function auction_route_exists(string $name): bool
    {
        return Display::routeExists($name);
    }
}

if (! function_exists('csp_nonce')) {
    /** The per-request CSP nonce set by App\Http\Middleware\SecurityHeaders, or '' when there is none (console, tests that skip the middleware). */
    function csp_nonce(): string
    {
        return (string) (request()->attributes->get('csp_nonce') ?? '');
    }
}
