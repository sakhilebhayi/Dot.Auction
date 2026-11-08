<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is ENFORCED. Every page of the application was run in a real browser under the enforced policy (184
    | page loads, desktop and phone widths, every role, with the bell, theme switch and a bid placed): no violations. Set
    | SECURITY_CSP_REPORT_ONLY=true to switch the same policy to report-only without a deploy (the browser then reports what it
    | would have blocked to /csp-report and blocks nothing), for instance while a new page is being built.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

    /*
    | Extra origins the page may open connections to, beyond itself (SECURITY_CSP_CONNECT_SRC, space separated, e.g.
    | "https://api.example.com"). The Reverb websocket host is added automatically when real-time is on; nothing else is allowed, so
    | a script that got onto a page could not send what it reads to another site.
    */
    'csp_connect_src' => array_values(array_filter(array_map('trim', explode(' ', (string) env('SECURITY_CSP_CONNECT_SRC', ''))))),

    /*
    | The public address browsers use to reach Reverb (the same REVERB_* values the JavaScript bundle is built with).
    */
    'reverb_public' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT'),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted hosts (Host header allowlist)
    |--------------------------------------------------------------------------
    | Comma separated host names this app answers for (TRUSTED_HOSTS). Empty means: the host of APP_URL. A request
    | for any other Host gets a 400, so a forged Host header cannot make the app build links (password reset, Paystack
    | callback, notification URLs) that point somewhere else. Add the www form or a second domain here when one exists.
    | Not enforced while APP_URL is still the localhost default, in the local environment or in tests, and never for
    | /up (a health probe sent to an IP address or with no Host must keep working).
    */
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),
];
