<?php

namespace App\Http\Controllers;

use App\Models\AuctionSettlement;
use App\Services\Auctions\ViewerActivity;
use App\Support\Display;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $salesToPay = $activity->salesToPay($user);
        $lateBuyers = $activity->lateBuyers($user);
        $decisions = $activity->reserveDecisions($user);
        $seller = $activity->sellerSummary($user, $now);
        $operatorQueue = $user->isPlatformOperator() ? $activity->paymentsNeedingAPerson() : 0;

        $needs = $this->needs($decisions->count(), $salesToPay, $outbid->count(), $leadingSoon->count(), $lateBuyers, $operatorQueue, $activity->owedCents($user));

        return view('dashboard', [
            'isNew' => false,
            'needs' => $needs,
            'summary' => $this->summary($leading->count(), $outbid->count(), $seller),
            'outbid' => $outbid,
            'leading' => $leading,
            'openBids' => $openBids,
            'salesToPay' => $salesToPay,
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
     * @param  \Illuminate\Support\Collection<int, AuctionSettlement>  $salesToPay
     * @param  \Illuminate\Support\Collection<int, AuctionSettlement>  $lateBuyers
     * @return list<array{tone: string, icon: string, text: string, action: string, href: string}>
     */
    private function needs(int $decisions, $salesToPay, int $outbid, int $leadingSoon, $lateBuyers, int $operatorQueue, int $owedCents): array
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

        $overdue = $salesToPay->where('status', AuctionSettlement::PAYMENT_OVERDUE);
        $awaiting = $salesToPay->where('status', AuctionSettlement::AWAITING_PAYMENT);

        if ($overdue->isNotEmpty()) {
            $first = $overdue->first();
            $needs[] = [
                'tone' => 'urgent', 'icon' => 'alert',
                'text' => $overdue->count() === 1
                    ? 'Payment is overdue on '.$first->auction->title.' ('.$first->amount().'). You can still pay until the seller cancels the sale.'
                    : 'Payment is overdue on '.$overdue->count().' sales. You can still pay until the sellers cancel them.',
                'action' => $overdue->count() === 1 ? 'Pay now' : 'See payments',
                'href' => $overdue->count() === 1 ? route('settlements.show', $first) : route('settlements.index'),
            ];
        }

        if ($awaiting->isNotEmpty()) {
            $first = $awaiting->first();
            $needs[] = [
                'tone' => 'notice', 'icon' => 'clock',
                'text' => $awaiting->count() === 1
                    ? 'You won '.$first->auction->title.'. Pay '.$first->amount().($first->payment_due_at ? ' by '.Display::time($first->payment_due_at, 'D j M, H:i', true) : '').'.'
                    : 'You owe '.\App\Models\Auction::money($owedCents / 100).' across '.$salesToPay->count().' won lots.',
                'action' => $awaiting->count() === 1 ? 'Pay now' : 'See payments',
                'href' => $awaiting->count() === 1 ? route('settlements.show', $first) : route('settlements.index'),
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
