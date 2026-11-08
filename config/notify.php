<?php

/*
|--------------------------------------------------------------------------
| Dot.Notify (email-worthy events)
|--------------------------------------------------------------------------
| Events this app already raises in-app are also posted, server to server, to
| Dot.Notify's ingest API (POST /api/ingest/events), queued and signed. Both
| the URL and the secret must be set or nothing is sent. The secret is this
| app's NOTIFY_INGEST_SECRET_<KEY> value in Dot.Notify's .env. Notify only
| emails when the recipient's company has also enabled the event there.
*/

return [
    'url' => env('NOTIFY_INGEST_URL'),
    'secret' => env('NOTIFY_INGEST_SECRET'),

    // Dot.Notify's name for this app (it picks the signing secret by this key).
    'platform' => env('NOTIFY_PLATFORM_KEY', 'auction'),

    // Seconds, for connecting and for the whole request.
    // The reserve-not-met e-mail is sent only once Dot.Notify's catalogue has the `auction.reserve_not_met` event, or
    // Notify answers 422 and the application log fills with refusals. The in-app notice is always sent.
    'reserve_not_met_event' => (bool) env('NOTIFY_RESERVE_NOT_MET_EVENT', false),

    'timeout' => 5,

    // After `threshold` consecutive failed calls, stop calling for `pause_seconds`.
    'circuit' => [
        'threshold' => 5,
        'pause_seconds' => 60,
    ],
];
