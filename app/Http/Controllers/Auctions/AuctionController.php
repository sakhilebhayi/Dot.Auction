<?php

namespace App\Http\Controllers\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Buyer-facing browse + detail routes (platform-loop pass). Previously the
 * only way to reach an auction at all was the seller's own dashboard table;
 * there was no marketplace view and no route that rendered the existing
 * (but view-less) App\Livewire\Auctions\BidPanel component. See wiki.md
 * roadmap — "buyer-facing bidding UI" — this closes that specific gap.
 */
class AuctionController extends Controller
{
    /**
     * Searchable, filterable auction marketplace. Draft auctions are
     * excluded — they belong to AuctionPolicy::view()'s seller-only rule.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Auction::class);

        // Query-string values are untrusted: an array (?q[]=x), text where an id belongs (?category=abc) or a number
        // too big for the column must narrow to "no filter" or "no results", never become a 500.
        $rawSearch = $request->query('q');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $category = $this->positiveId($request->query('category'));
        $status = is_string($request->query('status')) ? $request->query('status') : null;

        $auctions = Auction::query()
            ->browsable()
            ->with(['category', 'images'])
            // Case-insensitive on every database (PostgreSQL's LIKE is not), with % and _ taken literally.
            ->when($search !== '', fn ($q) => $q->whereRaw(
                'lower(title) like ? escape ?',
                ['%'.addcslashes(mb_strtolower($search), '\\%_').'%', '\\'],
            ))
            ->when($category !== null, fn ($q) => $q->where('category_id', $category))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('ends_at')
            ->paginate(12)
            ->withQueryString();

        // The same set the list shows: other sellers' drafts are not counted (nor hinted at).
        $categories = AuctionCategory::withCount(['auctions' => fn ($q) => $q->browsable()])->orderBy('name')->get();

        return view('auctions.index', [
            'auctions' => $auctions,
            'categories' => $categories,
            'search' => $search,
            'category' => $category,
            'status' => $status,
        ]);
    }

    /**
     * A database id from the query string: a positive integer that fits a bigint column, or null.
     */
    private function positiveId(mixed $value): ?int
    {
        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * Auction detail + live bidding page. Authorization delegated to
     * AuctionPolicy. Reserve price is never passed to the view — only the
     * reserveMet() boolean — so the Blade template has no way to leak it.
     */
    public function show(Auction $auction): View
    {
        Gate::authorize('view', $auction);

        $auction->load(['category', 'seller', 'items', 'images']);

        // HasUserScope already restricts this relation query to the
        // authenticated user's own watchlist rows.
        $isWatching = auth()->check() && $auction->watchlists()->exists();

        // Payment hand-off status is only for the two parties to the sale.
        $settlement = AuctionSettlement::query()
            ->where('auction_id', $auction->id)
            ->where(fn ($q) => $q->where('winner_id', auth()->id())->orWhere('seller_id', auth()->id()))
            ->first();

        return view('auctions.show', [
            'auction' => $auction,
            'isWatching' => $isWatching,
            'settlement' => $settlement,
        ]);
    }
}
