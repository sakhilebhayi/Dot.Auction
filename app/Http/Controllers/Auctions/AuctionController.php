<?php

namespace App\Http\Controllers\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\LotBrowser;
use App\Support\PageRange;
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
     * Searchable, filterable, sortable auction marketplace. Draft auctions are excluded: they belong to
     * AuctionPolicy::view()'s seller-only rule. State, category, search, sort and page all live in the query string, and
     * every value in it is untrusted: anything that is not a known state, a positive id or a known sort key narrows to
     * "no filter", never to a 500.
     */
    public function index(Request $request, LotBrowser $browser): View
    {
        Gate::authorize('viewAny', Auction::class);

        $user = $request->user();
        $now = now();

        $rawSearch = $request->query('q');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 100) : '';
        $category = $this->positiveId($request->query('category'));
        $state = LotBrowser::normaliseState($request->query('state') ?? $request->query('status'));
        $sort = LotBrowser::normaliseSort($request->query('sort'));

        $query = $browser->inState($browser->base($search, $category), $state, $now);
        $browser->withCardData($query, $user);
        $browser->sorted($query, $sort, $now);

        $auctions = PageRange::within($query->paginate(12)->withQueryString());

        // One grouped query each for the tab counts and the category chips, both for the same search the list answers.
        $counts = $browser->counts($search, $category, $now);
        $perCategory = $browser->inState($browser->base($search, null), $state, $now)
            ->toBase()->whereNotNull('category_id')->selectRaw('category_id, count(*) as lots')->groupBy('category_id')
            ->pluck('lots', 'category_id');
        $categories = AuctionCategory::query()->orderBy('name')->get()
            ->each(fn (AuctionCategory $c) => $c->setAttribute('lots', (int) ($perCategory[$c->id] ?? 0)))
            ->filter(fn (AuctionCategory $c) => $c->lots > 0 || $c->id === $category)
            ->values();

        return view('auctions.index', [
            'auctions' => $auctions,
            'categories' => $categories,
            'counts' => $counts,
            'search' => $search,
            'category' => $category,
            'activeCategory' => $category !== null ? $categories->firstWhere('id', $category) ?? AuctionCategory::find($category) : null,
            'state' => $state,
            'sort' => $sort,
            'userId' => $user->id,
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
    public function show(Request $request, Auction $auction): View
    {
        Gate::authorize('view', $auction);

        // Having opened the lot, an unread "you were outbid" notice about it has been answered.
        if ($request->user() !== null) {
            OutbidNotification::markReadFor($request->user(), $auction->id);
        }

        $auction->load(['category', 'seller', 'items', 'images']);

        // Everything that depends on the viewer (the payment panel, leading or outbid, the seller's decision) is worked
        // out by the bid panel itself (App\Support\LotOutcome), so it stays right while the page is open.
        return view('auctions.show', ['auction' => $auction]);
    }
}
