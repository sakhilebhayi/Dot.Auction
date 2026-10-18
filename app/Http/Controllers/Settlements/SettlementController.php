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

        $settlements = AuctionSettlement::query()
            ->with('auction')
            ->where(fn ($q) => $q->where('winner_id', $userId)->orWhere('seller_id', $userId))
            ->latest('created_at')
            ->paginate(20);

        return view('settlements.index', ['settlements' => $settlements, 'payments' => app(PaystackConfig::class)]);
    }

    public function show(Request $request, AuctionSettlement $settlement): View
    {
        $this->partiesOnly($request, $settlement);

        $settlement->load(['auction', 'winner', 'seller']);

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
            return $this->back($settlement)->withErrors(['payment' => $e->getMessage()]);
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
            return $this->back($settlement)->withErrors([
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

        return $this->back($settlement)->with('status', $message);
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
