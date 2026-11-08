<?php

use App\Http\Controllers\Auctions\AuctionController;
use App\Http\Controllers\Auctions\AuctionImageController;
use App\Http\Controllers\Buyer\BidsController as BuyerBidsController;
use App\Http\Controllers\Buyer\WatchlistController as BuyerWatchlistController;
use App\Http\Controllers\Buyer\WonController as BuyerWonController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\Operator\SettlementPaymentController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\RealtimeHealthController;
use App\Http\Controllers\Seller\ImageController;
use App\Http\Controllers\Seller\ListingController;
use App\Http\Controllers\Settlements\SettlementController;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\Watchlist;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// Content-Security-Policy violation reports (the policy is report-only). The browser posts these by itself, so there is
// no session, no cookie, no CSRF token and no user; the controller keeps only the directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->middleware('throttle:60,1,csp-report')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('csp.report');

Route::get('/', function () {
    return view('welcome');
});

// Real-time infrastructure health check -- unauthenticated like Laravel's
// own /up (bootstrap/app.php), for uptime monitors/load balancers.
Route::get('/up/realtime', [RealtimeHealthController::class, 'check'])
    ->name('health.realtime');

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

// Paystack's server-to-server notification. No session, no cookies, no CSRF token (there is no browser
// and no user): the HMAC signature of the raw body is the authentication. See
// App\Http\Controllers\PaystackWebhookController.
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('throttle:paystack-webhook')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('webhooks.paystack');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/auctions', [AuctionController::class, 'index'])->name('auctions.index');
    Route::get('/auctions/{auction}', [AuctionController::class, 'show'])->name('auctions.show');
    // Listing photos sit on a private disk; this is the only way to read one.
    Route::get('/auctions/{auction}/images/{image}', [AuctionImageController::class, 'show'])
        ->name('auctions.images.show')->scopeBindings();

    // Seller listing management. Policy checks live in the controller and
    // SaveListingRequest; bid-dependent rules in App\Services\Auctions\ListingManager.
    Route::prefix('seller/auctions')->name('seller.auctions.')->group(function () {
        Route::get('/', [ListingController::class, 'index'])->name('index');
        Route::get('/create', [ListingController::class, 'create'])->name('create');
        Route::post('/', [ListingController::class, 'store'])->name('store');
        Route::get('/{auction}/edit', [ListingController::class, 'edit'])->name('edit');
        Route::put('/{auction}', [ListingController::class, 'update'])->name('update');
        Route::post('/{auction}/publish', [ListingController::class, 'publish'])->name('publish');
        Route::post('/{auction}/cancel', [ListingController::class, 'cancel'])->name('cancel');
        Route::delete('/{auction}', [ListingController::class, 'destroy'])->name('destroy');
        Route::post('/{auction}/images', [ImageController::class, 'store'])->name('images.store');
        Route::delete('/{auction}/images/{image}', [ImageController::class, 'destroy'])->name('images.destroy')->scopeBindings();
    });

    // Paying for a won auction. Both parties see the page; only the winner pays; only the seller
    // cancels (once overdue). Policy checks live in the controller.
    Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
    Route::get('/settlements/{settlement}', [SettlementController::class, 'show'])->name('settlements.show');
    Route::post('/settlements/{settlement}/pay', [SettlementController::class, 'pay'])
        ->middleware('throttle:12,1')->name('settlements.pay');
    Route::get('/settlements/{settlement}/payment/callback', [SettlementController::class, 'callback'])
        ->middleware('throttle:30,1')->name('settlements.payment.callback');
    Route::post('/settlements/{settlement}/cancel', [SettlementController::class, 'cancel'])
        ->middleware('throttle:12,1')->name('settlements.cancel');

    // Read-only operator list of payments (money that needs a person is listed first).
    Route::get('/operator/settlement-payments', [SettlementPaymentController::class, 'index'])
        ->middleware('operator')
        ->name('operator.settlement-payments.index');

    // --- discover ---
    // Home, the buyer pages (my bids, watching, won) and the notification inbox. Everything here reads only the signed-in
    // person's own rows. The shell links buyer.* through auction_route().
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/bids', [BuyerBidsController::class, 'index'])->name('buyer.bids');
    Route::get('/watchlist', [BuyerWatchlistController::class, 'index'])->name('buyer.watchlist');
    Route::delete('/watchlist/{auction}', [BuyerWatchlistController::class, 'destroy'])->name('buyer.watchlist.destroy');
    Route::get('/won', [BuyerWonController::class, 'index'])->name('buyer.won');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    // --- /discover ---
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}

// --- dev ---
// Local convenience only: sign in as a seeded demo user without typing a password. The block is not even
// registered unless the app is running in the "local" or "testing" environment, so production has no such route.
if (app()->environment('local', 'testing')) {
    // /dev/login/{id or email}[?to=/path]  The optional `to` must be a path on this site.
    Route::get('/dev/login/{who}', function (Request $request, string $who) {
        $user = ctype_digit($who)
            ? \App\Models\User::find((int) $who)
            : \App\Models\User::where('email', $who)->first();

        abort_if($user === null, 404, 'No such user.');

        Auth::login($user);
        $request->session()->regenerate();

        $to = (string) $request->query('to', '/dashboard');

        return redirect(str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : '/dashboard');
    })->where('who', '.+')->name('dev.login');

    // The design-system gallery: every component in both themes, with invented, unsaved lots (App\Support\KitData).
    Route::get('/dev/kit', function () {
        $now = now();

        return view('dev.kit', ['lots' => \App\Support\KitData::lots($now), 'now' => $now]);
    })->name('dev.kit');

    // TESTING ONLY (the browser suite in tests/e2e/auction): a fake Paystack checkout and a switch for the fake gateway's mode.
    // Registered only when AUCTION_E2E_FAKE_PAYSTACK is on as well, in these two environments only.
    if (config('paystack.e2e_fake') === true) {
        Route::get('/dev/fake-paystack-mode/{mode}', function (string $mode) {
            abort_unless(in_array($mode, ['ok', 'unreachable', 'error'], true), 404);
            \Illuminate\Support\Facades\Cache::put(\App\Services\Paystack\E2eFakePaystackClient::MODE_KEY, $mode, now()->addHour());

            return response('mode '.$mode);
        })->name('dev.fake-paystack.mode');

        Route::get('/dev/fake-paystack/{reference}', function (string $reference) {
            $init = \Illuminate\Support\Facades\Cache::get(\App\Services\Paystack\E2eFakePaystackClient::ref($reference, 'init'));
            abort_if($init === null, 404);

            return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Fake Paystack checkout</title></head><body>'
                .'<main><h1>Fake Paystack checkout</h1><p>Reference '.e($reference).' for '.e($init['currency']).' '.number_format($init['amount'] / 100, 2).'.</p>'
                .'<p><a id="fake-pay" href="/dev/fake-paystack/'.e($reference).'/complete?result=success">Pay</a> '
                .'<a id="fake-fail" href="/dev/fake-paystack/'.e($reference).'/complete?result=failed">Decline</a> '
                .'<a id="fake-abandon" href="/dev/fake-paystack/'.e($reference).'/complete?result=abandoned">Close checkout</a></p></main></body></html>');
        })->name('dev.fake-paystack.checkout');

        Route::get('/dev/fake-paystack/{reference}/complete', function (Request $request, string $reference) {
            $init = \Illuminate\Support\Facades\Cache::get(\App\Services\Paystack\E2eFakePaystackClient::ref($reference, 'init'));
            abort_if($init === null, 404);
            \Illuminate\Support\Facades\Cache::put(
                \App\Services\Paystack\E2eFakePaystackClient::ref($reference, 'result'),
                in_array($request->query('result'), ['success', 'failed', 'abandoned', 'pending'], true) ? $request->query('result') : 'abandoned',
                now()->addHour(),
            );

            return redirect()->away($init['callback'].'?trxref='.$reference.'&reference='.$reference);
        })->name('dev.fake-paystack.complete');
    }

    Route::get('/dev/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('dev.logout');
}
// --- /dev ---
