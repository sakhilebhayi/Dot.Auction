<?php

namespace App\Http\Controllers\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionImage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read a listing photo. The files sit on a private disk;
 * this route applies the same rule as the auction page itself (a draft is
 * the seller's alone, a published auction is visible to signed-in users)
 * and, through scoped route binding, only serves an image under the
 * auction it belongs to.
 */
class AuctionImageController extends Controller
{
    public function show(Auction $auction, AuctionImage $image): StreamedResponse
    {
        Gate::authorize('view', $auction);

        $disk = Storage::disk(AuctionImage::DISK);

        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, null, [
            'Content-Type' => $image->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
