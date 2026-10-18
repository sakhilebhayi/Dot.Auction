<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UploadAuctionImagesRequest;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Services\Auctions\AuctionImageManager;
use App\Services\Auctions\ListingRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Seller-side photo management. Photos may change for as long as the
 * auction is a draft or live, including after the first bid: they are part
 * of the description, not of the commercial terms.
 */
class ImageController extends Controller
{
    public function __construct(private readonly AuctionImageManager $images) {}

    public function store(UploadAuctionImagesRequest $request, Auction $auction): RedirectResponse
    {
        try {
            $added = $this->images->add($auction, $request->file('images'));
        } catch (ListingRejected $e) {
            return redirect()->route('seller.auctions.edit', $auction)->withErrors(['images' => $e->getMessage()]);
        }

        $count = count($added);

        return redirect()->route('seller.auctions.edit', $auction)
            ->with('status', $count === 1 ? '1 photo added.' : "{$count} photos added.");
    }

    public function destroy(Auction $auction, AuctionImage $image): RedirectResponse
    {
        Gate::authorize('update', $auction);

        try {
            $this->images->remove($auction, $image);
        } catch (ListingRejected $e) {
            return redirect()->route('seller.auctions.edit', $auction)->withErrors(['images' => $e->getMessage()]);
        }

        return redirect()->route('seller.auctions.edit', $auction)->with('status', 'Photo removed.');
    }
}
