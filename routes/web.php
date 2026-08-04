<?php

use App\Http\Controllers\Auctions\AuctionController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use Illuminate\Support\Facades\Route;


Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

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
        $activeAuctions = \App\Models\Auction::where('seller_id', $userId)->where('status', 'active')->count();
        $totalAuctions  = \App\Models\Auction::where('seller_id', $userId)->count();
        $totalBids      = \App\Models\Bid::whereHas('auction', fn ($q) => $q->where('seller_id', $userId))->count();
        $recentAuctions = \App\Models\Auction::where('seller_id', $userId)
            ->with('category')->latest()->limit(8)->get();
        $categories = \App\Models\AuctionCategory::withCount([
            'auctions' => fn ($q) => $q->where('seller_id', $userId),
        ])->get();
        // HasUserScope already restricts this query to the authenticated
        // user's own watchlist rows.
        $watchlistCount = \App\Models\Watchlist::count();
        $endingSoon = \App\Models\Auction::query()
            ->endingSoon(24)
            ->with('category')
            ->orderBy('ends_at')
            ->limit(5)
            ->get();
        return view('dashboard', compact(
            'activeAuctions', 'totalAuctions', 'totalBids', 'recentAuctions',
            'categories', 'watchlistCount', 'endingSoon'
        ));
    })->name('dashboard');

    Route::get('/auctions', [AuctionController::class, 'index'])->name('auctions.index');
    Route::get('/auctions/{auction}', [AuctionController::class, 'show'])->name('auctions.show');
});
