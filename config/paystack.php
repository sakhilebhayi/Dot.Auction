<?php

/*
|--------------------------------------------------------------------------
| Paystack (settlement payments)
|--------------------------------------------------------------------------
| TEST MODE ONLY unless you say otherwise twice. A secret key starting "sk_live_" is refused (never
| sent, logged without the key) unless PAYSTACK_ALLOW_LIVE=true is ALSO set. A key with any other
| prefix is refused. Keys are read from the environment only; this file never contains one.
| With no PAYSTACK_SECRET_KEY nothing changes: settlements stay "awaiting payment" with today's
| honest message. See README, "Paying for a won auction (Paystack)".
*/

return [

    // Server-side key: sk_test_... in test mode. Blank = online payment is switched off.
    'secret_key' => env('PAYSTACK_SECRET_KEY'),

    // pk_test_... Kept for completeness. The redirect flow used here never needs it in the browser.
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),

    // Pinned to https. Anything else is refused.
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    // The second switch a live key needs. Leave false until the owner has decided to take real money.
    'allow_live' => (bool) env('PAYSTACK_ALLOW_LIVE', false),

    // Seconds, for connecting and for the whole request. No redirects are followed, no retries are made.
    'timeout' => 10,

    // Minutes a started payment is reused ("Pay now" twice reopens the same checkout) before it is retired.
    'attempt_ttl_minutes' => (int) env('PAYSTACK_ATTEMPT_TTL_MINUTES', 30),

    // Webhook calls allowed per minute per IP, counted before the signature is checked.
    'webhook_attempts_per_minute' => (int) env('PAYSTACK_WEBHOOK_PER_MINUTE', 120),

];
