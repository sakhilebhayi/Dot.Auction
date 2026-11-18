<?php

use App\Sso\AuctionProvisioner;

/*
 * Dot.Auction's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 */
return [
    'provisioner' => AuctionProvisioner::class,

    // Auction has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link is only ever a same-origin path (the package enforces that); it may open any signed-in page of the app
    // (lots, my bids, settlements, the seller area). The allow-list is every path; the deny-list adds the
    // machine and sign-in doors so a link can never name them.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|dev|webhooks|api|broadcasting|livewire|csp-report|up)(/|\?|$)#i'],
    ],

    // Nothing extra is exempt. The Paystack webhook, Sanctum, /api, broadcasting auth, /csp-report and /up are fixed
    // machine paths in the package and are never intercepted.
    'allow_paths' => [],
];
