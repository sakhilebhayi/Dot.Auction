<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\SaveListingRequest;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionSettlement;
use App\Models\ReserveNotMetProposal;
use App\Services\Auctions\ListingManager;
use App\Services\Auctions\ListingRejected;
use App\Services\DisplayTime;
use App\Services\Paystack\PaystackConfig;
use App\Support\PageRange;
use App\Support\SellerLots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The seller's side of Dot.Auction: create, edit, publish, cancel and list
 * their own auctions. Every rule that depends on bids runs in
 * ListingManager under a row lock; this controller authorises, validates
 * and reports outcomes.
 */
class ListingController extends Controller
{
    public function __construct(private readonly ListingManager $listings) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $now = now();
        $endingSoon = (int) config('auction.ending_soon_window_minutes', 60);
        $state = $request->query('state');
        $state = is_string($state) && SellerLots::isState($state) ? $state : null;

        $auctions = SellerLots::apply(
            Auction::query()
                ->where('seller_id', $user->id)
                // Everything a row shows is loaded here, so the page costs the same number of queries for 1 lot or 15:
                // the category, the cover photo, the winning bid (for the Sold or Not sold word) and the bid count.
                ->with(['category', 'images', 'winningBid'])
                ->withCount('bids'),
            $state,
            $now,
            $endingSoon,
        )->paginate(15)->withQueryString();
        PageRange::within($auctions);

        $ids = $auctions->getCollection()->modelKeys();

        // The reserve decision a row is waiting on, and the payment behind a sold row: one query each for the whole page.
        $proposals = ReserveNotMetProposal::query()
            ->whereIn('auction_id', $ids)
            ->where('status', 'pending')
            ->with('bid.bidder')
            ->get()
            ->keyBy('auction_id');
        $byId = $auctions->getCollection()->keyBy('id');
        $proposals->each(fn (ReserveNotMetProposal $proposal) => $proposal->setRelation('auction', $byId->get($proposal->auction_id)));

        $settlements = AuctionSettlement::query()
            ->whereIn('auction_id', $ids)
            ->get()
            ->keyBy('auction_id');

        return view('seller.auctions.index', [
            'auctions' => $auctions,
            'state' => $state,
            'counts' => SellerLots::counts($user->id, $now, $endingSoon),
            'proposals' => $proposals,
            'settlements' => $settlements,
            'payments' => app(PaystackConfig::class),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Auction::class);

        return $this->form(new Auction);
    }

    public function store(SaveListingRequest $request): RedirectResponse
    {
        try {
            $auction = $this->listings->create($request->user(), $request->listingData());
        } catch (ListingRejected $e) {
            return back()->withInput()->withErrors(['listing' => $e->getMessage()]);
        }

        return redirect()->route('seller.auctions.edit', $auction)
            ->with('status', 'Draft saved. Review it, then publish when you are ready for bids.');
    }

    public function edit(Auction $auction): View
    {
        Gate::authorize('update', $auction);

        return $this->form($auction);
    }

    public function update(SaveListingRequest $request, Auction $auction): RedirectResponse
    {
        try {
            $this->listings->update($auction, $request->listingData());
        } catch (ListingRejected $e) {
            return back()->withInput()->withErrors(['listing' => $e->getMessage()]);
        }

        return redirect()->route('seller.auctions.edit', $auction)->with('status', 'Changes saved.');
    }

    public function publish(Auction $auction): RedirectResponse
    {
        Gate::authorize('manage', $auction);

        try {
            $published = $this->listings->publish($auction);
        } catch (ListingRejected $e) {
            return back()->withErrors(['publish' => $e->getMessage()]);
        }

        return redirect()->route('seller.auctions.index')->with('status', $published->isScheduled()
            ? "\"{$published->title}\" is scheduled and opens ".Auction::localTime($published->starts_at, 'M j, Y H:i', true).'.'
            : "\"{$published->title}\" is live.");
    }

    public function cancel(Auction $auction): RedirectResponse
    {
        Gate::authorize('manage', $auction);

        try {
            $this->listings->cancel($auction);
        } catch (ListingRejected $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('seller.auctions.index')->with('status', "\"{$auction->title}\" was cancelled.");
    }

    public function destroy(Auction $auction): RedirectResponse
    {
        Gate::authorize('manage', $auction);

        try {
            $this->listings->delete($auction);
        } catch (ListingRejected $e) {
            return back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->route('seller.auctions.index')->with('status', 'Draft deleted.');
    }

    private function form(Auction $auction): View
    {
        $money = fn (?string $amount) => $amount === null ? '' : Auction::fromCents(Auction::toCents($amount));
        $moment = fn ($date) => DisplayTime::inputValue($date);

        if ($auction->exists) {
            $auction->load('images');
        }

        $categories = AuctionCategory::orderBy('name')->pluck('name', 'id')->all();

        if ($categories === []) {
            // A listing cannot be saved without a category. Make the cause findable instead of leaving an empty list.
            Log::error('A seller opened the listing form but there are no auction categories. Run: php artisan auction:seed-categories');
        }

        return view('seller.auctions.form', [
            'auction' => $auction,
            'maxImages' => (int) config('auction.images.max_per_auction', 6),
            'termsLocked' => $auction->exists && $auction->hasBids(),
            'categories' => $categories,
            'values' => [
                'title' => $auction->title,
                'description' => $auction->description,
                'category_id' => $auction->category_id,
                'starting_price' => $auction->exists ? $money($auction->starting_price) : '',
                'bid_increment' => $auction->exists ? $money($auction->bid_increment) : '',
                'reserve_price' => $money($auction->reserve_price),
                'buy_now_price' => $money($auction->buy_now_price),
                'starts_at' => $auction->exists && $auction->starts_at->isFuture() ? $moment($auction->starts_at) : '',
                // A new lot starts with a closing time a week out at 18:00 local time: a suggestion the seller can change,
                // inside the allowed duration, so the first save is not a date puzzle.
                'ends_at' => $auction->exists
                    ? $moment($auction->ends_at)
                    : $moment(DisplayTime::toLocal(now())->addDays(7)->setTime(18, 0)),
            ],
        ]);
    }
}
