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
        $userId = auth()->id();
        $activeAuctions = \App\Models\Auction::where('seller_id', $userId)->where('status', 'active')->count();
        $totalAuctions  = \App\Models\Auction::where('seller_id', $userId)->count();
        $totalBids      = \App\Models\Bid::whereHas('auction', fn ($q) => $q->where('seller_id', $userId))->count();
        $recentAuctions = \App\Models\Auction::where('seller_id', $userId)
            ->with('category')->latest()->limit(8)->get();
        $categories = \App\Models\AuctionCategory::withCount([
            'auctions' => fn ($q) => $q->where('seller_id', $userId),
        ])->get();
        $watchlistCount = \App\Models\Watchlist::where('user_id', $userId)->count();
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
