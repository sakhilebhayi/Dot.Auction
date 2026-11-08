<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Services\Auctions\ViewerActivity;
use App\Services\Paystack\PaystackConfig;
use App\Support\SettlementPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Home. Role-aware without checking a role: everything is read from the signed-in person's own rows, so one account
 * can be a bidder, a seller and the operator at once, and a new account sees a welcome rather than a page of zeros.
 *
 * The page answers one question first, "what needs me?", in words with a direct link, and then shows only the sections
 * that have data: my bids, sales to pay, what I watch, what I sell, and what closes soon.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ViewerActivity $activity): View|RedirectResponse
    {
        $user = $request->user();

        // Jetstream's HasTeams::currentTeam() resolves to null for a user who owns no team (provisioned outside
        // CreateNewUser); the shell dereferences it, so send such a user to team creation first.
        if (! $user->currentTeam) {
            return redirect()->route('teams.create');
        }

        $now = now();

        if (! $activity->hasAnyHistory($user)) {
            return view('dashboard', [
                'isNew' => true,
                'endingSoon' => $activity->endingSoonOnPlatform($user, 4, $now),
                'needs' => [],
                'summary' => null,
            ]);
        }

        $openBids = $activity->openBids($user, $now);
        $outbid = $openBids->filter(fn ($a) => ! $a->viewer_leads)->values();
        $leading = $openBids->filter(fn ($a) => (bool) $a->viewer_leads)->values();
        $leadingSoon = $leading->filter(fn ($a) => $a->ends_at->lte($now->copy()->addHours(ViewerActivity::SOON_HOURS)))->values();
        $resultPending = $activity->resultPending($user, $now);
        $salesToPay = $activity->salesToPay($user);
        // One reading of every unpaid sale (payable now, late, or waiting for payment to open): the same one the sale page uses.
        $positions = SettlementPosition::many($salesToPay);
        // A buyer cannot be late while payment is closed, so the seller is not asked to chase one.
        $lateBuyers = app(PaystackConfig::class)->canTakePayments() ? $activity->lateBuyers($user) : collect();
        $decisions = $activity->reserveDecisions($user);
        $seller = $activity->sellerSummary($user, $now);
        $operatorQueue = $user->isPlatformOperator() ? $activity->paymentsNeedingAPerson() : 0;

        $needs = $this->needs($decisions->count(), $positions, $outbid->count(), $leadingSoon->count(), $lateBuyers, $operatorQueue, $resultPending);

        return view('dashboard', [
            'isNew' => false,
            'needs' => $needs,
            'summary' => $this->summary($leading->count(), $outbid->count(), $seller),
            'outbid' => $outbid,
            'leading' => $leading,
            'openBids' => $openBids,
            'resultPending' => $resultPending,
            'salesToPay' => $positions,
            'decisions' => $decisions,
            'seller' => $seller,
            'sellingNow' => $seller && $seller['live'] > 0 ? $activity->sellingNow($user, 6, $now) : collect(),
            'watchedSoon' => $activity->watchedEndingSoon($user, 4, $now),
            'watchingCount' => $activity->watchingCount($user),
            'endingSoon' => $activity->endingSoonOnPlatform($user, 4, $now),
        ]);
    }

    /**
     * The "needs you" lines, most pressing first. Each is a sentence in plain words and a link that goes straight to the
     * place to act: ['tone' => urgent|notice|info, 'icon' => ..., 'text' => ..., 'action' => label, 'href' => url].
     *
     * @param  Collection<int, SettlementPosition>  $positions  the winner's unpaid sales
     * @param  Collection<int, AuctionSettlement>  $lateBuyers
     * @param  Collection<int, Auction>  $resultPending  lots bid on whose result has not landed
     * @return list<array{tone: string, icon: string, text: string, action: ?string, href: ?string}>
     */
    private function needs(int $decisions, $positions, int $outbid, int $leadingSoon, $lateBuyers, int $operatorQueue, $resultPending): array
    {
        $needs = [];
        $plural = fn (int $n, string $one, string $many) => $n === 1 ? $one : $many;

        if ($decisions > 0) {
            $needs[] = [
                'tone' => 'notice', 'icon' => 'pause',
                'text' => $decisions === 1
                    ? 'A lot of yours ended below its reserve. Accept the top bid or end it unsold.'
                    : $decisions.' of your lots ended below their reserve. Accept the top bids or end them unsold.',
                'action' => 'Decide', 'href' => '#decisions',
            ];
        }

        $overdue = $positions->filter->isOverdue()->values();
        $due = $positions->filter(fn (SettlementPosition $p) => $p->state() === SettlementPosition::DUE)->values();
        $waiting = $positions->filter->isWaiting()->values();

        if ($overdue->isNotEmpty()) {
            $first = $overdue->first();
            $needs[] = [
                'tone' => 'urgent', 'icon' => 'alert',
                'text' => $overdue->count() === 1
                    ? $first->winnerLine($first->settlement->auction->title)
                    : 'Payment is overdue on '.$overdue->count().' sales. You can still pay until the sellers cancel them.',
                'action' => $overdue->count() === 1 ? 'Pay now' : 'See payments',
                'href' => $overdue->count() === 1 ? route('settlements.show', $first->settlement) : route('settlements.index'),
            ];
        }

        if ($due->isNotEmpty()) {
            $first = $due->first();
            $needs[] = [
                'tone' => 'notice', 'icon' => 'clock',
                'text' => $due->count() === 1
                    ? $first->winnerLine($first->settlement->auction->title)
                    : 'You owe '.SettlementPosition::money((int) $due->sum(fn ($p) => $p->owedNowCents())).' across '.$due->count().' won lots.',
                'action' => $due->count() === 1 ? 'Pay now' : 'See payments',
                'href' => $due->count() === 1 ? route('settlements.show', $first->settlement) : route('settlements.index'),
            ];
        }

        // Payment is not open: there is nothing to do, so this line has no button. It says what will be owed so it is no surprise.
        if ($waiting->isNotEmpty()) {
            $first = $waiting->first();
            $needs[] = [
                'tone' => 'info', 'icon' => 'clock',
                'text' => $waiting->count() === 1
                    ? 'Waiting for payment to open on '.$first->settlement->auction->title.'. You will owe '.$first->settlement->amount().' once it does.'
                    : 'Waiting for payment to open on '.$waiting->count().' won lots. You will owe '.SettlementPosition::money((int) $waiting->sum(fn ($p) => $p->owedLaterCents())).' once it does.',
                'action' => null, 'href' => null,
            ];
        }

        // A lot the person bid on that has closed but has no result yet stays here until it does. Nothing to press, so they are quiet lines.
        $settling = $resultPending->filter(fn ($a) => $a->status === 'active')->values();
        $deciding = $resultPending->filter(fn ($a) => $a->status === 'pending_review')->values();

        if ($deciding->isNotEmpty()) {
            $needs[] = [
                'tone' => 'info', 'icon' => 'pause',
                'text' => $deciding->count() === 1
                    ? 'The seller is deciding on '.$deciding->first()->title.', where you hold the top bid. Nothing to do yet.'
                    : 'The seller is deciding on '.$deciding->count().' lots where you hold the top bid. Nothing to do yet.',
                'action' => $deciding->count() === 1 ? 'Open lot' : 'See my bids',
                'href' => $deciding->count() === 1 ? route('auctions.show', $deciding->first()) : auction_route('buyer.bids').'#waiting',
            ];
        }

        if ($settling->isNotEmpty()) {
            $needs[] = [
                'tone' => 'info', 'icon' => 'clock',
                'text' => $settling->count() === 1
                    ? 'Bidding closed on '.$settling->first()->title.'. The result is being recorded and shows here within a few minutes.'
                    : 'Bidding closed on '.$settling->count().' lots you bid on. The results are being recorded and show here within a few minutes.',
                'action' => $settling->count() === 1 ? 'Open lot' : 'See my bids',
                'href' => $settling->count() === 1 ? route('auctions.show', $settling->first()) : auction_route('buyer.bids').'#ended',
            ];
        }

        if ($outbid > 0) {
            $needs[] = [
                'tone' => 'urgent', 'icon' => 'bids',
                'text' => 'You were outbid on '.$outbid.' '.$plural($outbid, 'lot', 'lots').' that '.$plural($outbid, 'is', 'are').' still open.',
                'action' => 'Bid again', 'href' => auction_route('buyer.bids').'#outbid',
            ];
        }

        if ($leadingSoon > 0) {
            $needs[] = [
                'tone' => 'info', 'icon' => 'hourglass',
                'text' => $leadingSoon === 1
                    ? 'A lot you lead closes within 24 hours.'
                    : $leadingSoon.' lots you lead close within 24 hours.',
                'action' => 'See my bids', 'href' => auction_route('buyer.bids').'#leading',
            ];
        }

        foreach ($lateBuyers->take(2) as $sale) {
            $needs[] = [
                'tone' => 'notice', 'icon' => 'clock',
                'text' => 'The buyer has not paid for '.$sale->auction->title.' by the deadline. You can wait or cancel the sale.',
                'action' => 'Open the sale', 'href' => route('settlements.show', $sale),
            ];
        }
        if ($lateBuyers->count() > 2) {
            $needs[] = [
                'tone' => 'notice', 'icon' => 'clock',
                'text' => ($lateBuyers->count() - 2).' more buyers are late paying.',
                'action' => 'See payments', 'href' => route('settlements.index'),
            ];
        }

        if ($operatorQueue > 0) {
            $needs[] = [
                'tone' => 'urgent', 'icon' => 'shield',
                'text' => $operatorQueue === 1
                    ? 'One payment matched no sale and needs an operator.'
                    : $operatorQueue.' payments matched no sale and need an operator.',
                'action' => 'Review payments', 'href' => route('operator.settlement-payments.index', ['show' => 'attention']),
            ];
        }

        return $needs;
    }

    /**
     * One plain sentence that describes the person's standing, built only from what is true and non-zero.
     *
     * @param  array{live: int, closing_soon: int, scheduled: int, pending: int, drafts: int, total: int}|null  $seller
     */
    private function summary(int $leading, int $outbid, ?array $seller): ?string
    {
        $parts = [];

        if ($leading > 0) {
            $parts[] = 'You lead '.$leading.' '.($leading === 1 ? 'lot' : 'lots');
        }
        if ($outbid > 0) {
            $parts[] = ($parts === [] ? 'You were' : 'were').' outbid on '.$outbid.' '.($outbid === 1 ? 'lot' : 'lots');
        }
        if ($seller && $seller['live'] > 0) {
            $parts[] = ($parts === [] ? 'You are' : 'are').' selling '.$seller['live'].' '.($seller['live'] === 1 ? 'lot' : 'lots');
        }

        if ($parts === []) {
            return null;
        }

        $last = array_pop($parts);

        return ($parts === [] ? $last : implode(', ', $parts).' and '.$last).'.';
    }
}
