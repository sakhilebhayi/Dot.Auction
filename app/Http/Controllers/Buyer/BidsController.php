<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\Auctions\ViewerActivity;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * My bids: the lots the signed-in person has bid on, grouped by what they can do next. Own rows only (every query in
 * ViewerActivity filters on the user id; there is no id in the URL to tamper with).
 */
class BidsController extends Controller
{
    public function index(Request $request, ViewerActivity $activity): View
    {
        $user = $request->user();
        $open = $activity->openBids($user);

        return view('buyer.bids', [
            'outbid' => $open->filter(fn ($a) => ! $a->viewer_leads)->values(),
            'leading' => $open->filter(fn ($a) => (bool) $a->viewer_leads)->values(),
            'waiting' => $activity->waitingOnSeller($user),
            'ended' => $activity->endedBids($user),
        ]);
    }
}
