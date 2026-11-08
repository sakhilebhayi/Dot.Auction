<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Livewire call (the bell's poll, the lot page's poll, a Watch button) is a session request like any other, and every
 * saved session request ages the flash data: what the last request flashed is readable by the next one and gone after it.
 * When such a call lands between a redirect and the page it redirects to (it often does: the bell polls, the lot page polls),
 * the flash message is spent on a response nobody reads, and the person never sees "Stopped watching ..." or, worse,
 * "Payment received". Found by the real-browser run (tests/e2e/auction): about one redirect in three lost its message.
 *
 * A Livewire call never shows a flash message of its own, so it hands the flash data on untouched: `reflash()` keeps what the
 * previous request flashed for the request after this one.
 */
class KeepFlashForLivewire
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader('X-Livewire') && $request->hasSession()) {
            $request->session()->reflash();
        }

        return $next($request);
    }
}
