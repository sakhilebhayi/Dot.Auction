<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\Auctions\ViewerActivity;
use App\Support\SettlementPosition;
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
        $totals = SettlementPosition::totals(SettlementPosition::many($activity->salesToPay($user)));

        return view('buyer.won', [
            'lots' => $activity->won($user),
            // Payable today, and owed once payment opens: one reading shared with Home and the sale page.
            'toPay' => $totals['now_count'],
            'owed' => $totals['now_cents'] > 0 ? Auction::money($totals['now_cents'] / 100) : null,
            'toPayLater' => $totals['later_count'],
            'owedLater' => $totals['later_cents'] > 0 ? Auction::money($totals['later_cents'] / 100) : null,
            'overdueCount' => $totals['overdue_count'],
        ]);
    }
}
