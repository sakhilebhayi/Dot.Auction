<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UploadAuctionImagesRequest;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Services\Auctions\AuctionImageManager;
use App\Services\Auctions\ListingRejected;
use Illuminate\Http\JsonResponse;
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

    /**
     * The photo drop zone sends the same request with XMLHttpRequest (to show upload progress) and asks for JSON; the
     * rules and the outcome are identical, only the envelope differs. A validation failure is answered 422 with the
     * framework's JSON error bag by the form request itself.
     */
    public function store(UploadAuctionImagesRequest $request, Auction $auction): RedirectResponse|JsonResponse
    {
        try {
            $added = $this->images->add($auction, $request->file('images'));
        } catch (ListingRejected $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['images' => [$e->getMessage()]]], 422);
            }

            return redirect()->route('seller.auctions.edit', $auction)->withErrors(['images' => $e->getMessage()]);
        }

        $count = count($added);
        $message = $count === 1 ? '1 photo added.' : "{$count} photos added.";

        if ($request->expectsJson()) {
            // The page reloads after a successful upload and shows the message once.
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message, 'added' => $count]);
        }

        return redirect()->route('seller.auctions.edit', $auction)->with('status', $message);
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
