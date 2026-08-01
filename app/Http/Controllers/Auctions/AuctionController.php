<?php

namespace App\Http\Controllers\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionCategory;
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

        $search   = trim((string) $request->get('q', ''));
        $category = $request->get('category');
        $status   = $request->get('status');

        $auctions = Auction::query()
            ->browsable()
            ->with('category')
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->when($category, fn ($q) => $q->where('category_id', $category))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('ends_at')
            ->paginate(12)
            ->withQueryString();

        $categories = AuctionCategory::withCount('auctions')->orderBy('name')->get();

        return view('auctions.index', [
            'auctions'   => $auctions,
            'categories' => $categories,
            'search'     => $search,
            'category'   => $category,
            'status'     => $status,
        ]);
    }

    /**
     * Auction detail + live bidding page. Authorization delegated to
     * AuctionPolicy. Reserve price is never passed to the view — only the
     * reserveMet() boolean — so the Blade template has no way to leak it.
     */
    public function show(Auction $auction): View
    {
        Gate::authorize('view', $auction);

        $auction->load(['category', 'seller', 'items']);

        $isWatching = auth()->check()
            && $auction->watchlists()->where('user_id', auth()->id())->exists();

        return view('auctions.show', [
            'auction'    => $auction,
            'isWatching' => $isWatching,
        ]);
    }
}
