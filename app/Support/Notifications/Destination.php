<?php

namespace App\Support\Notifications;

/**
 * Where a notification takes you. The address was stored by our own code when the notification was created, but a
 * redirect is only ever made to this site: a path, or an absolute address on the application's own host (returned as a path).
 */
final class Destination
{
    public static function safe(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2000) {
            return null;
        }

        // Control characters and backslashes are how a "path" becomes a different host in some clients.
        if (preg_match('/[\x00-\x1f\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($host === null || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $own = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ]);

        if (! in_array(strtolower($host), array_map('strtolower', $own), true)) {
            return null;
        }

        // Followed as a path on the host the person is on now (the stored address may name the public host, or a sibling of it).
        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
