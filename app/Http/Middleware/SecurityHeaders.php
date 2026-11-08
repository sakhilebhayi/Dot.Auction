<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS requests
 * only) Strict-Transport-Security. HSTS covers this host alone: includeSubDomains and preload are left out on
 * purpose because many sibling platforms live on neighbouring subdomains and each sets its own.
 *
 * The Content-Security-Policy is still REPORT-ONLY by default (SECURITY_CSP_REPORT_ONLY=false enforces the same policy with no
 * deploy), but it is now the policy we intend to enforce, and it needs no third party:
 *
 * - Fonts, CSS and JavaScript are all self-hosted (public/fonts, public/build). The Tailwind CDN, unpkg, Google Fonts, Material
 *   Symbols and ui-avatars are gone, so none of them is allowed any more.
 * - Inline <script> and <style> elements carry a per-request nonce (csp_nonce(), also given to Vite and Livewire). Only the nonce
 *   and 'self' may run script. 'unsafe-eval' stays: Livewire's bundled Alpine compiles directive expressions with Function().
 * - Inline style="" ATTRIBUTES stay allowed through style-src-attr: the design system sets custom properties (--p on the closing
 *   rail) and the pages still being rebuilt use them. Attributes cannot run script.
 *
 * Paystack needs no allowance: checkout is a server-side redirect to Paystack's hosted payment page and back
 * (redirect()->away), so no js.paystack.co script, checkout.paystack.com frame or Paystack form is loaded here.
 * Why it is not enforced yet: docs/R42_DECISIONS.md (shell section).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // One nonce per request, available to the views (csp_nonce()) and to Vite's script and link tags.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            (config('security.csp_report_only', true) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($nonce),
        ];

        if ($request->isSecure() && (int) config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data: blob:",
            // Reverb websockets are wss: in production; local development talks plaintext ws:.
            "connect-src 'self' wss: https:".(app()->isLocal() ? ' ws:' : ''),
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ]);
    }
}
