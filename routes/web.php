<?php

use App\Http\Controllers\Auctions\AuctionController;
use App\Http\Controllers\Auctions\AuctionImageController;
use App\Http\Controllers\Auth\EcosystemAuthController;
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
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
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
    Route::get('/dashboard', function () {
        // Jetstream's HasTeams::currentTeam() self-heals a null current_team_id
        // by switching to the user's personal team — but that fallback itself
        // resolves to null for a user who owns no team at all (e.g. a user
        // provisioned outside App\Actions\Fortify\CreateNewUser, which is the
        // only place a personal team gets created). The shared navigation
        // partial dereferences currentTeam unconditionally, so send such users
        // to team creation before they hit that instead of crashing there.
        if (! auth()->user()->currentTeam) {
            return redirect()->route('teams.create');
        }

        $userId = auth()->id();
        $activeAuctions = Auction::where('seller_id', $userId)->where('status', 'active')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->count();
        $totalAuctions = Auction::where('seller_id', $userId)->count();
        $totalBids = Bid::whereHas('auction', fn ($q) => $q->where('seller_id', $userId))->count();
        $recentAuctions = Auction::where('seller_id', $userId)
            ->with('category')->withCount('bids')->latest('id')->limit(8)->get();
        $categories = AuctionCategory::withCount([
            'auctions' => fn ($q) => $q->where('seller_id', $userId),
        ])->get();
        // HasUserScope already restricts this query to the authenticated
        // user's own watchlist rows.
        $watchlistCount = Watchlist::count();
        $endingSoon = Auction::query()
            ->endingSoon(24)
            ->with('category')
            ->orderBy('ends_at')
            ->limit(5)
            ->get();
        $pendingReserveProposals = ReserveNotMetProposal::whereHas(
            'auction', fn ($q) => $q->where('seller_id', $userId)
        )->where('status', 'pending')->with(['auction', 'bid.bidder'])->get();

        return view('dashboard', compact(
            'activeAuctions', 'totalAuctions', 'totalBids', 'recentAuctions',
            'categories', 'watchlistCount', 'endingSoon', 'pendingReserveProposals'
        ));
    })->name('dashboard');

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
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
