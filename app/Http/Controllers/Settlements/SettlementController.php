<?php

namespace App\Http\Controllers\Settlements;

use App\Http\Controllers\Controller;
use App\Models\AuctionSettlement;
use App\Services\Auctions\SettlementActionRejected;
use App\Services\Auctions\SettlementLifecycle;
use App\Services\Auctions\SettlementPayer;
use App\Services\Auctions\SettlementPaymentRecorder;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackConfig;
use App\Support\PageRange;
use App\Support\SettlementPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The payment page for a sold auction, for its two parties only.
 *
 * Anyone who is neither the winner nor the seller gets a 404 (the sale is none of their business and
 * its existence is not confirmed); the seller gets a 403 on winner-only actions. The amount is never
 * read from the request: SettlementPayer takes it from the settlement row.
 */
class SettlementController extends Controller
{
    public function index(Request $request): View
    {
        $userId = $request->user()->getKey();
        $role = $request->query('role');
        $role = in_array($role, ['buying', 'selling'], true) ? $role : null;

        $mine = fn ($q) => $q->where(fn ($q) => $q->where('winner_id', $userId)->orWhere('seller_id', $userId));

        $settlements = AuctionSettlement::query()
            ->with('auction')
            ->when($role === 'buying', fn ($q) => $q->where('winner_id', $userId))
            ->when($role === 'selling', fn ($q) => $q->where('seller_id', $userId))
            ->when($role === null, $mine)
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        PageRange::within($settlements);

        $counts = AuctionSettlement::query()->tap($mine)->toBase()
            ->selectRaw('count(*) as all_rows, coalesce(sum(case when winner_id = ? then 1 else 0 end), 0) as buying_rows, coalesce(sum(case when seller_id = ? then 1 else 0 end), 0) as selling_rows', [$userId, $userId])
            ->first();

        $payments = app(PaystackConfig::class);

        return view('settlements.index', [
            'settlements' => $settlements,
            'payments' => $payments,
            'role' => $role,
            'counts' => ['all' => (int) $counts->all_rows, 'buying' => (int) $counts->buying_rows, 'selling' => (int) $counts->selling_rows],
            // Winners' sales that can be paid right now (the one thing on this page that needs a person) and the ones that will be
            // owed once payment opens: read through SettlementPosition so this page agrees with Home, Won and the sale page.
            ...$this->owing($userId),
        ]);
    }

    /**
     * @return array{toPay: int, toPayLater: int, owedLater: int}
     */
    private function owing(int|string $userId): array
    {
        $unpaid = AuctionSettlement::query()->where('winner_id', $userId)
            ->whereIn('status', [AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAYMENT_OVERDUE])
            ->limit(500)->get();
        $totals = SettlementPosition::totals(SettlementPosition::many($unpaid));

        return ['toPay' => $totals['now_count'], 'toPayLater' => $totals['later_count'], 'owedLater' => $totals['later_cents']];
    }

    public function show(Request $request, AuctionSettlement $settlement): View
    {
        $this->partiesOnly($request, $settlement);

        $settlement->load(['auction', 'events' => fn ($q) => $q->orderBy('created_at')->orderBy('id')]);

        return view('settlements.show', [
            'settlement' => $settlement,
            'payments' => app(PaystackConfig::class),
            'isWinner' => $settlement->isWinner($request->user()),
            'isSeller' => $settlement->isSeller($request->user()),
            'receipt' => $settlement->payments()->where('status', 'paid')->latest('id')->first(),
        ]);
    }

    public function pay(Request $request, AuctionSettlement $settlement, SettlementPayer $payer): RedirectResponse
    {
        $this->partiesOnly($request, $settlement);
        Gate::authorize('pay', $settlement);

        try {
            $payment = $payer->start($settlement, $request->user());
        } catch (SettlementActionRejected $e) {
            return $this->back($settlement)->withErrors(['payment' => $e->getMessage()]);
        } catch (PaymentGatewayException $e) {
            return $this->back($settlement)->with('payment_state', 'gateway')->withErrors(['payment' => $e->getMessage()]);
        }

        // Off to Paystack's own page: card details never touch this app.
        return redirect()->away($payment->authorization_url);
    }

    /**
     * Where Paystack sends the winner back. Whatever is in the query string is ignored: the answer
     * comes from asking Paystack, server to server, about the attempt this app recorded.
     */
    public function callback(Request $request, AuctionSettlement $settlement, SettlementPayer $payer): RedirectResponse
    {
        $this->partiesOnly($request, $settlement);
        Gate::authorize('pay', $settlement);

        try {
            $outcome = $payer->confirm($settlement);
        } catch (PaymentGatewayException $e) {
            return $this->back($settlement)->with('payment_state', 'unconfirmed')->withErrors([
                'payment' => 'We could not confirm your payment with Paystack just now. Nothing has changed on this sale. '
                    .'If you were charged it will be confirmed automatically; you can also reload this page in a minute.',
            ]);
        }

        $message = match ($outcome) {
            SettlementPaymentRecorder::PAID, SettlementPaymentRecorder::ALREADY_PAID => 'Payment received. Thank you.',
            SettlementPaymentRecorder::PENDING => 'Your payment is still being processed by Paystack. This page will show it as paid once Paystack confirms.',
            SettlementPaymentRecorder::FAILED => 'The payment did not go through and you were not charged. You can try again.',
            SettlementPaymentRecorder::HELD => 'Paystack reported a payment we could not match to this sale, so it has been passed to an operator. It has not been applied.',
            default => 'No payment has been started for this sale yet.',
        };

        // Which Paystack return this was, so the page can title it (paid, pending, failed, held) instead of showing a bare line.
        $state = match ($outcome) {
            SettlementPaymentRecorder::PAID, SettlementPaymentRecorder::ALREADY_PAID => 'paid',
            SettlementPaymentRecorder::PENDING => 'pending',
            SettlementPaymentRecorder::FAILED => 'failed',
            SettlementPaymentRecorder::HELD => 'held',
            default => 'not_started',
        };

        return $this->back($settlement)->with('status', $message)->with('payment_state', $state);
    }

    public function cancel(Request $request, AuctionSettlement $settlement, SettlementLifecycle $lifecycle): RedirectResponse
    {
        $this->partiesOnly($request, $settlement);
        Gate::authorize('cancel', $settlement);

        try {
            $lifecycle->cancel($settlement, $request->user());
        } catch (SettlementActionRejected $e) {
            return $this->back($settlement)->withErrors(['cancel' => $e->getMessage()]);
        }

        return $this->back($settlement)->with('status', 'The sale is cancelled and the buyer has been told. The auction has not been relisted.');
    }

    private function partiesOnly(Request $request, AuctionSettlement $settlement): void
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $settlement), 404);
    }

    private function back(AuctionSettlement $settlement): RedirectResponse
    {
        return redirect()->route('settlements.show', $settlement);
    }
}
