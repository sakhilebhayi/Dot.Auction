<?php

namespace App\Models;

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

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
        });
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function url(): string
    {
        return route('auctions.images.show', [$this->auction_id, $this]);
    }
}
