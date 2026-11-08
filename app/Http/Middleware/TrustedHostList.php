<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

/**
 * Which Host headers the app answers for (R-42 F-19). Feeds Laravel's TrustHosts middleware (bootstrap/app.php):
 * `$middleware->trustHosts(at: fn () => TrustedHostList::patterns(), subdomains: false)`.
 */
final class TrustedHostList
{
    /**
     * The regular expressions Symfony's request matches the Host header against; an empty list switches the check off.
     *
     * @return array<int, string>
     */
    public static function patterns(?Request $request = null): array
    {
        $request ??= request();

        // A health probe may come by IP address or without a Host at all. It builds no URLs, so let it through.
        if ($request->is('up', 'up/*')) {
            return [];
        }

        $hosts = array_map('strtolower', (array) config('security.trusted_hosts', []));

        if ($hosts === []) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            // APP_URL still at its default means the host was never configured; locking everyone out is worse.
            if (! is_string($appHost) || $appHost === '' || $appHost === 'localhost') {
                return [];
            }

            $hosts = [strtolower($appHost)];
        }

        return array_map(fn (string $host) => '^'.preg_quote($host, '/').'$', array_unique($hosts));
    }
}
