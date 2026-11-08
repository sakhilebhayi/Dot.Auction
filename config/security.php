<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is REPORT-ONLY: the browser reports what it would have blocked to /csp-report and blocks nothing.
    | Set SECURITY_CSP_REPORT_ONLY=false to enforce the same policy without a deploy, once the reports have been reviewed.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),

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
