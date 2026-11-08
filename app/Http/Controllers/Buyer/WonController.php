<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\Auctions\ViewerActivity;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Won: the lots the signed-in person won, each with its sale and the way to pay. Own rows only.
 */
class WonController extends Controller
{
    public function index(Request $request, ViewerActivity $activity): View
    {
        $user = $request->user();
        $owedCents = $activity->owedCents($user);

        return view('buyer.won', [
            'lots' => $activity->won($user),
            'toPay' => $activity->salesToPay($user)->count(),
            'owed' => $owedCents > 0 ? Auction::money($owedCents / 100) : null,
        ]);
    }
}
