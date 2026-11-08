<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Watchlist;
use App\Services\Auctions\ViewerActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Watching: the lots the signed-in person follows, the ones closing first at the top. Own rows only: Watchlist carries the
 * HasUserScope global scope, and every query here also names the user.
 */
class WatchlistController extends Controller
{
    public function index(Request $request, ViewerActivity $activity): View
    {
        return view('buyer.watchlist', ['lots' => $activity->watching($request->user())]);
    }

    /**
     * Stop watching a lot. Works without JavaScript (a form), and removing a lot that is not watched is a no-op.
     */
    public function destroy(Request $request, Auction $auction): RedirectResponse
    {
        // A draft is only the seller's: its title must not reach anyone else, not even in this message.
        Gate::authorize('view', $auction);

        Watchlist::query()->where('user_id', $request->user()->id)->where('auction_id', $auction->id)->delete();

        return redirect()->route('buyer.watchlist')->with('status', 'Stopped watching "'.$auction->title.'".');
    }
}
