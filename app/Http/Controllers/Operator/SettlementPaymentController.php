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
    /** @var array<int, string> the statuses an operator can filter by, in tab order */
    private const FILTERS = [
        SettlementPayment::UNMATCHED,
        SettlementPayment::INITIATED,
        SettlementPayment::PAID,
        SettlementPayment::FAILED,
        SettlementPayment::EXPIRED,
    ];

    public function index(Request $request): View
    {
        // `?show=attention` is the original filter name and keeps working; `?status=` adds the other statuses.
        $status = $request->query('status');
        if ($request->query('show') === 'attention') {
            $status = SettlementPayment::UNMATCHED;
        }
        $status = is_string($status) && in_array($status, self::FILTERS, true) ? $status : null;

        $query = SettlementPayment::query()
            ->with('settlement.auction')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'unmatched' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        $counts = SettlementPayment::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n);

        return view('operator.settlement-payments', [
            'payments' => $query->paginate(50)->withQueryString(),
            'attention' => (int) ($counts[SettlementPayment::UNMATCHED] ?? 0),
            'attentionOnly' => $status === SettlementPayment::UNMATCHED,
            'status' => $status,
            'counts' => $counts,
            'allCount' => (int) $counts->sum(),
            'filters' => self::FILTERS,
            'config' => app(PaystackConfig::class),
        ]);
    }
}
