<?php

namespace App\Support;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * What a lot page says, for one lot and one viewer (R-42 W4). Every status and every kind of viewer gets its own words;
 * the live copy is never reused for a lot that is not live.
 *
 * States (what the lot is):   draft, scheduled, live, ending, closing, sold, unsold, pending, cancelled
 *   closing  = ends_at has passed but the settlement job has not run yet (the result is being recorded)
 *   pending  = ended below the reserve, waiting for the seller (status pending_review)
 * Roles (who is looking):     seller, leader, outbid, winner, loser, visitor
 *   leader / outbid are the live roles, winner / loser the ended ones; a viewer who never bid is a visitor.
 *
 * Only reserve met / not met is ever written, never an amount. The class reads; it changes nothing.
 */
final class LotOutcome
{
    public const STATES = ['draft', 'scheduled', 'live', 'ending', 'closing', 'sold', 'unsold', 'pending', 'cancelled'];

    public string $state;

    public string $role;

    public bool $isSeller;

    public bool $viewerLeads;

    public bool $isBuyNowSale = false;

    public bool $saleCancelled = false;

    public int $bidCount = 0;

    public ?int $viewerBidCents = null;

    public int $currentCents;

    public int $minimumCents;

    /** The amount the next bid has to beat, over the viewer's own highest bid, in cents (outbid viewers only). */
    public ?int $gapCents = null;

    public string $priceLabel;

    public string $price;

    /** @var array{tone: string, icon: string, headline: string, lines: list<string>}|null */
    public ?array $box = null;

    public bool $canBid = false;

    public bool $canBuyNow = false;

    public ?Bid $winningBid = null;

    public ?ReserveNotMetProposal $proposal = null;

    public ?AuctionSettlement $settlement = null;

    private function __construct(public Auction $auction, public ?User $viewer) {}

    public static function for(Auction $auction, ?User $viewer, ?int $bidCount = null): self
    {
        $o = new self($auction, $viewer);
        $o->read($bidCount);
        $o->classify();
        $o->write();

        return $o;
    }

    private function read(?int $bidCount): void
    {
        $a = $this->auction;
        $viewerId = $this->viewer?->getKey();

        $this->isSeller = $viewerId !== null && $viewerId === $a->seller_id;
        $this->bidCount = $bidCount ?? (int) ($a->bids_count ?? $a->bids()->count());
        $this->currentCents = Auction::toCents($a->current_price);
        $this->minimumCents = $a->minimumBidCents();
        $this->winningBid = $a->bids()->where('is_winning', true)->with('bidder')->reorder()->first();
        $this->proposal = $a->reserveProposal()->with('bid')->first();

        if ($viewerId !== null) {
            $mine = Bid::query()->where('auction_id', $a->getKey())->where('bidder_id', $viewerId)->get(['amount']);
            $this->viewerBidCents = $mine->isEmpty() ? null : $mine->map(fn (Bid $b) => Auction::toCents($b->amount))->max();

            $this->settlement = AuctionSettlement::query()
                ->where('auction_id', $a->getKey())
                ->where(fn ($q) => $q->where('winner_id', $viewerId)->orWhere('seller_id', $viewerId))
                ->first();
        }

        $this->viewerLeads = $viewerId !== null && $this->winningBid !== null && $this->winningBid->bidder_id === $viewerId;
    }

    private function classify(): void
    {
        $a = $this->auction;
        $status = (string) $a->status;

        $this->state = match (true) {
            $status === 'draft' => 'draft',
            $status === 'cancelled' => 'cancelled',
            $status === 'pending_review' => 'pending',
            $status === 'ended' => $this->endedHadASale() ? 'sold' : 'unsold',
            $a->isScheduled() => 'scheduled',
            $a->ends_at->lte(now()) => 'closing',
            $a->ends_at->lte(now()->addMinutes((int) config('auction.ending_soon_window_minutes', 60))) => 'ending',
            default => 'live',
        };

        if ($this->state === 'sold') {
            $this->saleCancelled = $this->settlement?->status === 'cancelled';
            $this->isBuyNowSale = $this->boughtWithBuyNow();
        }

        $live = in_array($this->state, ['live', 'ending'], true);

        $this->role = match (true) {
            $this->isSeller => 'seller',
            $this->viewerBidCents === null => 'visitor',
            $live || $this->state === 'closing' => $this->viewerLeads ? 'leader' : 'outbid',
            $this->state === 'sold' => $this->viewerLeads ? 'winner' : 'loser',
            $this->state === 'pending' => $this->viewerLeads ? 'leader' : 'outbid',
            default => $this->viewerLeads ? 'leader' : 'loser',
        };

        if ($this->role === 'outbid' && $this->viewerBidCents !== null) {
            $this->gapCents = max(0, $this->minimumCents - $this->viewerBidCents);
        }

        $this->canBuyNow = $live && ! $this->isSeller && $a->buyNowAvailable();
        // The leader is not offered a form: there is no proxy bidding, so raising your own winning bid would only raise the
        // price you pay. BidPlacer still accepts such a bid (no rule changed); the page just does not invite it.
        $this->canBid = $live && ! $this->isSeller && ! $this->viewerLeads;
    }

    /** Status 'ended' with a winning bid is a sale, unless the seller rejected that bid (the winning flag stays on it). */
    private function endedHadASale(): bool
    {
        return $this->winningBid !== null && ($this->proposal === null || $this->proposal->status !== 'rejected');
    }

    private function boughtWithBuyNow(): bool
    {
        $bid = $this->winningBid;
        $a = $this->auction;

        if ($bid === null || $a->buy_now_price === null) {
            return false;
        }

        // Buy Now records a winning bid at exactly the Buy Now price and pulls the close to that moment.
        return Auction::toCents($bid->amount) === Auction::toCents($a->buy_now_price)
            && $bid->created_at !== null
            && abs($a->ends_at->getTimestamp() - $bid->created_at->getTimestamp()) <= 5;
    }

    public function isLive(): bool
    {
        return in_array($this->state, ['live', 'ending'], true);
    }

    /** The word for <x-ds.status>. A reserve-rejected lot is "unsold" even though a winning flag remains on its last bid; a lot past its time but not yet settled is "closing", never "unsold". */
    public function statusState(): string
    {
        return $this->state;
    }

    private function money(int|string|null $amount): string
    {
        return Auction::money(is_int($amount) ? $amount / 100 : $amount);
    }

    private function at(?CarbonInterface $at, string $format = 'D j M, H:i'): string
    {
        return Display::time($at, $format, true);
    }

    private function reserveLine(): ?string
    {
        if ($this->auction->reserve_price === null) {
            return null;
        }

        return $this->auction->reserveMet()
            ? 'The reserve is met.'
            : 'The reserve is not met. If the lot closes below it, the seller decides whether to accept the highest bid.';
    }

    private function write(): void
    {
        $a = $this->auction;
        $hasBids = $this->bidCount > 0;
        $current = $this->money($a->current_price);

        [$this->priceLabel, $this->price] = match ($this->state) {
            'sold' => ['Sold for', (string) $a->current_price],
            'pending' => ['Highest bid', (string) $a->current_price],
            'unsold' => $hasBids ? ['Highest bid', (string) $a->current_price] : ['Starting bid', (string) $a->starting_price],
            'cancelled' => $hasBids ? ['Last bid', (string) $a->current_price] : ['Starting bid', (string) $a->starting_price],
            'scheduled', 'draft' => ['Starting bid', (string) $a->starting_price],
            default => $hasBids ? ['Current bid', (string) $a->current_price] : ['Starting bid', (string) $a->starting_price],
        };

        $this->box = match ($this->state) {
            'draft' => $this->draftBox(),
            'scheduled' => $this->scheduledBox(),
            'live', 'ending' => $this->liveBox($current),
            'closing' => $this->closingBox($current),
            'sold' => $this->soldBox($current),
            'unsold' => $this->unsoldBox($current),
            'pending' => $this->pendingBox($current),
            'cancelled' => $this->cancelledBox(),
        };
    }

    /** @return array{tone: string, icon: string, headline: string, lines: list<string>} */
    private function box(string $tone, string $icon, string $headline, string ...$lines): array
    {
        return ['tone' => $tone, 'icon' => $icon, 'headline' => $headline, 'lines' => array_values(array_filter($lines, fn ($l) => $l !== ''))];
    }

    private function draftBox(): array
    {
        return $this->box('info', 'pencil', 'This lot is a draft', 'Only you can see it. Buyers cannot find it or bid until you publish it.');
    }

    private function scheduledBox(): array
    {
        $a = $this->auction;
        $opens = $this->at($a->starts_at);

        if ($this->isSeller) {
            return $this->box('info', 'calendar', 'Your lot opens on '.$opens, 'Bidding starts at '.$this->money($a->starting_price).'. It closes on '.$this->at($a->ends_at).'.');
        }

        return $this->box('info', 'calendar', 'Not open yet', 'Bidding opens on '.$opens.' at '.$this->money($a->starting_price).'. You can place a bid from then.');
    }

    private function liveBox(string $current): ?array
    {
        $a = $this->auction;
        $reserve = $this->reserveLine();

        if ($this->role === 'seller') {
            $count = $this->bidCount === 0 ? 'Nobody has bid yet.' : ($this->bidCount === 1 ? '1 bid so far.' : $this->bidCount.' bids so far.');

            return $this->box('info', 'tag', 'This is your lot', 'Bidding is open. '.$count.($reserve !== null ? ' '.$this->sellerReserveLine() : ''), 'You cannot bid on your own lot.');
        }

        if ($this->role === 'leader') {
            return $this->box('success', 'check-circle', 'You are leading',
                'Your bid of '.$current.' is the highest. We will tell you if you are outbid.',
                $reserve ?? '');
        }

        if ($this->role === 'outbid') {
            $gap = $this->money($this->gapCents ?? 0);

            return $this->box('danger', 'alert', 'You were outbid',
                'Your last bid was '.$this->money($this->viewerBidCents).'. To take the lead, bid at least '.$this->money($this->minimumCents).', which is '.$gap.' more.');
        }

        // A visitor with no bid: the form is the message. One plain line about the state of the price.
        return null;
    }

    private function sellerReserveLine(): string
    {
        return $this->auction->reserveMet() ? 'The reserve is met.' : 'The reserve is not met yet.';
    }

    private function closingBox(string $current): array
    {
        $result = 'The result is being recorded and will appear here within a few minutes.';

        if ($this->role === 'leader') {
            return $this->box('notice', 'clock', 'Bidding has closed',
                'You held the highest bid, '.$current.'.',
                $this->auction->reserve_price !== null && ! $this->auction->reserveMet()
                    ? 'It is below the reserve, so the seller will decide whether to accept it. This page shows the result.'
                    : $result);
        }

        return $this->box('notice', 'clock', 'Bidding has closed', $result);
    }

    private function soldBox(string $current): array
    {
        $a = $this->auction;
        $when = $this->at($a->ends_at);
        $acceptedBelowReserve = $this->proposal?->status === 'accepted';

        if ($this->saleCancelled) {
            return $this->box('neutral', 'ban', 'Sale cancelled',
                'This lot was sold for '.$current.', then the seller cancelled the sale. Nothing is due.');
        }

        $how = $this->isBuyNowSale ? 'with Buy Now' : '';

        return match ($this->role) {
            'winner' => $this->box('success', 'gavel',
                $this->isBuyNowSale ? 'You bought this lot with Buy Now' : 'You won this lot',
                $this->isBuyNowSale ? 'You paid the Buy Now price of '.$current.' and bidding ended at once, on '.$when.'.' : 'You won it for '.$current.' when bidding closed on '.$when.'.',
                $acceptedBelowReserve ? 'The seller accepted your bid, which was below the reserve.' : '',
                'Payment and the next steps are below.'),
            'loser' => $this->box('neutral', 'gavel', 'You did not win this lot',
                ($this->isBuyNowSale ? 'Another bidder bought it with Buy Now for '.$current.'.' : 'It sold for '.$current.' on '.$when.'.').' Your highest bid was '.$this->money($this->viewerBidCents).'.'),
            'seller' => $this->box('success', 'gavel', $this->isBuyNowSale ? 'Sold with Buy Now' : 'Sold',
                'Sold for '.$current.($this->winningBid?->bidder ? ' to '.$this->winningBid->bidder->maskedName() : '').' on '.$when.'.',
                $acceptedBelowReserve ? 'You accepted a bid below the reserve.' : '',
                'Payment is tracked below.'),
            default => $this->box('neutral', 'gavel', 'Sold',
                'Sold for '.$current.' on '.$when.($how !== '' ? ' '.$how : '').', after '.($this->bidCount === 1 ? '1 bid' : $this->bidCount.' bids').'.'),
        };
    }

    private function unsoldBox(string $current): array
    {
        $rejected = $this->proposal?->status === 'rejected';

        if ($this->bidCount === 0) {
            return $this->box('neutral', 'no-sale', 'Not sold', 'Nobody bid on this lot, so it ended without a sale.');
        }

        if ($rejected) {
            return match ($this->role) {
                'seller' => $this->box('neutral', 'no-sale', 'You ended this lot without a sale', 'The highest bid, '.$current.', was below your reserve and you chose not to accept it.'),
                'leader' => $this->box('neutral', 'no-sale', 'The seller did not accept your bid',
                    'Your bid of '.$current.' was the highest, but below the reserve. No sale was made, so nothing is due.'),
                default => $this->box('neutral', 'no-sale', 'Not sold', 'The highest bid, '.$current.', was below the reserve and the seller chose not to accept it. No sale was made.'),
            };
        }

        return $this->box('neutral', 'no-sale', 'Not sold', 'This lot ended without a sale. The highest bid was '.$current.'.');
    }

    private function pendingBox(string $current): array
    {
        $since = $this->proposal?->awaiting_since;
        $sinceLine = $since ? 'Waiting since '.$this->at($since).'.' : '';

        return match ($this->role) {
            'seller' => $this->box('notice', 'pause', 'This lot ended below your reserve',
                'The highest bid is '.$current.'. Accept it and the bidder wins at that price, or end the lot without a sale.', $sinceLine),
            'leader' => $this->box('notice', 'pause', 'The seller is deciding',
                'Your bid of '.$current.' was the highest, but it is below the reserve. If the seller accepts it you win the lot at that price. If not, the lot ends without a sale.',
                $sinceLine, 'There is no deadline for the decision. This page shows the result.'),
            default => $this->box('notice', 'pause', 'The seller is deciding',
                'The highest bid, '.$current.', is below the reserve. The seller can accept it or end the lot without a sale.', $sinceLine, 'This page shows the result.'),
        };
    }

    private function cancelledBox(): array
    {
        return match ($this->role) {
            'seller' => $this->box('neutral', 'ban', 'You cancelled this lot', 'No sale was made.'),
            default => $this->box('neutral', 'ban', 'Cancelled', 'The seller cancelled this lot. No sale was made, so nothing is due.'),
        };
    }
}
