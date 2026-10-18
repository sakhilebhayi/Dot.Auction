<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Rules\DecodableImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Adds photos to an auction. The count limit is checked under the auction
 * row lock, so two simultaneous uploads cannot together pass it, and the
 * whole batch is all-or-nothing: if any file or row fails, files already
 * written are removed again.
 *
 * Each file is re-inspected here (defence in depth behind the form
 * request) and stored under a server-generated name whose extension comes
 * from the detected content type, never from what the client called it.
 */
class AuctionImageManager
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, AuctionImage>
     *
     * @throws ListingRejected
     */
    public function add(Auction|int $auction, array $files): array
    {
        $disk = Storage::disk(AuctionImage::DISK);
        $written = [];

        try {
            return DB::transaction(function () use ($auction, $files, $disk, &$written) {
                $id = $auction instanceof Auction ? $auction->getKey() : $auction;
                $locked = Auction::query()->lockForUpdate()->find($id)
                    ?? throw new ListingRejected('This auction no longer exists.');

                if (! in_array($locked->status, ['draft', 'active'], true)) {
                    throw new ListingRejected('Photos can no longer be changed on this auction.');
                }

                $max = (int) config('auction.images.max_per_auction', 6);
                $existing = AuctionImage::query()->where('auction_id', $locked->id)->count();

                if ($existing + count($files) > $max) {
                    $room = max(0, $max - $existing);

                    throw new ListingRejected("An auction can have at most {$max} photos. This one has {$existing}, so you can add {$room} more.");
                }

                $position = (int) (AuctionImage::query()->where('auction_id', $locked->id)->max('position') ?? -1);
                $created = [];

                foreach ($files as $file) {
                    $info = DecodableImage::inspect($file)
                        ?? throw new ListingRejected('One of the files is not a valid JPG, PNG or WebP photo.');

                    $path = $disk->putFileAs((string) $locked->id, $file, Str::uuid()->toString().'.'.$info['extension']);

                    if ($path === false) {
                        throw new ListingRejected('A photo could not be saved. Nothing was uploaded; please try again.');
                    }

                    $written[] = $path;

                    $created[] = AuctionImage::create([
                        'auction_id' => $locked->id,
                        'path' => $path,
                        'original_name' => Str::limit($file->getClientOriginalName(), 190, ''),
                        'mime' => $info['mime'],
                        'size_bytes' => (int) $file->getSize(),
                        'width' => $info['width'],
                        'height' => $info['height'],
                        'position' => ++$position,
                    ]);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            if ($written !== []) {
                $disk->delete($written);
            }

            throw $e;
        }
    }

    /**
     * @throws ListingRejected
     */
    public function remove(Auction $auction, AuctionImage $image): void
    {
        DB::transaction(function () use ($auction, $image) {
            $locked = Auction::query()->lockForUpdate()->find($auction->getKey());

            if ($locked === null || ! in_array($locked->status, ['draft', 'active'], true)) {
                throw new ListingRejected('Photos can no longer be changed on this auction.');
            }

            $image->delete();
        });
    }
}
