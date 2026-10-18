<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\SaveListingRequest;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Services\Auctions\ListingManager;
use App\Services\Auctions\ListingRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $auctions = Auction::query()
            ->where('seller_id', $request->user()->id)
            ->with('category')
            ->withCount('bids')
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'active' THEN 1 WHEN 'pending_review' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(15);

        return view('seller.auctions.index', ['auctions' => $auctions]);
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
            ? "\"{$published->title}\" is scheduled and opens ".$published->starts_at->format('M j, Y H:i').'.'
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
        $moment = fn ($date) => $date?->format('Y-m-d\TH:i') ?? '';

        return view('seller.auctions.form', [
            'auction' => $auction,
            'termsLocked' => $auction->exists && $auction->hasBids(),
            'categories' => AuctionCategory::orderBy('name')->pluck('name', 'id')->all(),
            'values' => [
                'title' => $auction->title,
                'description' => $auction->description,
                'category_id' => $auction->category_id,
                'starting_price' => $auction->exists ? $money($auction->starting_price) : '',
                'bid_increment' => $auction->exists ? $money($auction->bid_increment) : '',
                'reserve_price' => $money($auction->reserve_price),
                'buy_now_price' => $money($auction->buy_now_price),
                'starts_at' => $auction->exists && $auction->starts_at->isFuture() ? $moment($auction->starts_at) : '',
                'ends_at' => $moment($auction->ends_at),
            ],
        ]);
    }
}
