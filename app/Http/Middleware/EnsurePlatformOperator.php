<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as the `operator` alias. A guest is sent to sign in; anyone signed in who is not a
 * platform operator gets 403. The only thing consulted is the account's own flag.
 */
class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        abort_unless($user->isPlatformOperator(), 403);

        return $next($request);
    }
}
