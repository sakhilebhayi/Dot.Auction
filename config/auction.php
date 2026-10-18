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

    /*
    |--------------------------------------------------------------------------
    | Settlement currency
    |--------------------------------------------------------------------------
    | ISO 4217 code written to auction_settlements.currency. Amounts there
    | are integer minor units (cents). The symbol above is display only.
    */
    'currency_code' => env('AUCTION_CURRENCY_CODE', 'ZAR'),

    /*
    |--------------------------------------------------------------------------
    | Anti-snipe
    |--------------------------------------------------------------------------
    | A bid accepted when `snipe_window_minutes` or fewer remain (the exact
    | boundary counts) pushes ends_at out by `snipe_extension_minutes`.
    | `snipe_max_extensions` caps how many times one auction can be extended
    | in total; once reached, late bids are still accepted but the close no
    | longer moves. A window or maximum of 0 switches the feature off.
    */
    'snipe_window_minutes' => (int) env('AUCTION_SNIPE_WINDOW_MINUTES', 5),
    'snipe_extension_minutes' => (int) env('AUCTION_SNIPE_EXTENSION_MINUTES', 5),
    'snipe_max_extensions' => (int) env('AUCTION_SNIPE_MAX_EXTENSIONS', 6),

    /*
    |--------------------------------------------------------------------------
    | Ending-soon notices
    |--------------------------------------------------------------------------
    | auction:notify-ending-soon tells each watcher once, when an auction
    | has this many minutes or fewer left.
    */
    'ending_soon_window_minutes' => (int) env('AUCTION_ENDING_SOON_WINDOW_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Seller listings
    |--------------------------------------------------------------------------
    */
    'listing' => [
        // Shortest and longest an auction may run, measured from the moment
        // it opens to the moment it closes.
        'min_duration_minutes' => (int) env('AUCTION_MIN_DURATION_MINUTES', 60),
        'max_duration_days' => (int) env('AUCTION_MAX_DURATION_DAYS', 30),
        // Largest price accepted for any money field, in whole currency units.
        'max_price' => (int) env('AUCTION_MAX_PRICE', 100000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing images
    |--------------------------------------------------------------------------
    | Files live on the private `auction-images` disk and are only ever
    | served through the policy-checked auctions.images.show route.
    */
    'images' => [
        'max_per_auction' => (int) env('AUCTION_MAX_IMAGES', 6),
        'max_kilobytes' => (int) env('AUCTION_IMAGE_MAX_KB', 4096),
        // Reject anything that would decode to more pixels than this, so a
        // tiny file cannot expand into a memory-exhausting bitmap.
        'max_pixels' => (int) env('AUCTION_IMAGE_MAX_PIXELS', 16000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement payment
    |--------------------------------------------------------------------------
    | `payment_window_hours`: how long a winner has to pay, counted from when
    | the settlement is recorded (or, for sales recorded before online payment
    | was switched on, from when it was). Past it the settlement becomes
    | "payment overdue" and both sides are told. Nothing is relisted or
    | cancelled automatically; the seller decides.
    |
    | `operator_emails`: who may open the read-only operator list of payments
    | (comma separated). There is no operator flag on users yet. Matching is
    | exact, ignores case, and the account must be email-verified.
    */
    'payment_window_hours' => (int) env('AUCTION_PAYMENT_WINDOW_HOURS', 72),
    'operator_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('AUCTION_OPERATOR_EMAILS', ''))
    ))),

];
