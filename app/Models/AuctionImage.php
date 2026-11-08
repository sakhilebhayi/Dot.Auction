<?php

namespace App\Models;

use App\Services\Auctions\AuctionImageVariants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One photo of an auction. The file lives on the private `auction-images`
 * disk and is read only through the policy-checked auctions.images.show
 * route; `path` is never rendered into a page. Removing a row removes its
 * file, after the transaction that removed the row commits.
 */
class AuctionImage extends Model
{
    public const DISK = 'auction-images';

    protected $fillable = [
        'auction_id', 'path', 'original_name', 'mime', 'size_bytes', 'width', 'height', 'position',
    ];

    protected static function booted(): void
    {
        static::deleted(function (AuctionImage $image): void {
            $path = $image->path;

            DB::afterCommit(function () use ($path) {
                Storage::disk(self::DISK)->delete([$path, ...AuctionImageVariants::allPaths($path)]);
            });
        });
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * The policy-checked address of this photo. `$size` is thumb, medium or large (see AuctionImageVariants); with none the
     * response is the large copy. Prefer Auction::imageUrl() in views.
     */
    public function url(?string $size = null): string
    {
        $parameters = [$this->auction_id, $this];

        if ($size !== null && $size !== AuctionImageVariants::DEFAULT_SIZE) {
            $parameters['size'] = $size;
        }

        return route('auctions.images.show', $parameters);
    }
}
