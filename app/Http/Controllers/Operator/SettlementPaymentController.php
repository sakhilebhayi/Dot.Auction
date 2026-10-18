<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\SettlementPayment;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only list of every settlement payment, with those that need a person (unmatched money) first.
 * It never shows checkout links or secrets and offers no actions. Access is the `operator` middleware
 * (the users.is_platform_operator flag), applied on the route.
 */
class SettlementPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $attentionOnly = $request->query('show') === 'attention';

        $query = SettlementPayment::query()
            ->with('settlement.auction')
            ->when($attentionOnly, fn ($q) => $q->where('status', SettlementPayment::UNMATCHED))
            ->orderByRaw("CASE WHEN status = 'unmatched' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        return view('operator.settlement-payments', [
            'payments' => $query->paginate(50)->withQueryString(),
            'attention' => SettlementPayment::query()->where('status', SettlementPayment::UNMATCHED)->count(),
            'attentionOnly' => $attentionOnly,
            'config' => app(PaystackConfig::class),
        ]);
    }
}
