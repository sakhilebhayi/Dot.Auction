<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display currency
    |--------------------------------------------------------------------------
    | The single source for the currency symbol shown anywhere an amount is
    | printed (bid panel, dashboard, listings, messages). Amounts themselves
    | are stored as decimal(12,2) and compared as integer cents. Use
    | App\Models\Auction::money() rather than hard-coding a symbol.
    */
    'currency_symbol' => env('AUCTION_CURRENCY_SYMBOL', 'R'),

    /*
    |--------------------------------------------------------------------------
    | Bid rate limit
    |--------------------------------------------------------------------------
    | Maximum bid submissions per user within the decay window (seconds).
    | Every submission counts, accepted or not, to blunt scripted hammering.
    */
    'bid_rate_limit' => [
        'max_attempts' => (int) env('AUCTION_BID_MAX_PER_WINDOW', 20),
        'decay_seconds' => (int) env('AUCTION_BID_WINDOW_SECONDS', 60),
    ],

];
