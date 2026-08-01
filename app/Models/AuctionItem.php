<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `auction_items` table (item-level detail attached
 * to an auction: condition, physical location). The table has existed since
 * the original auction schema migration, but had no model class — added
 * here so the auction detail page (platform-loop pass) can display it.
 */
class AuctionItem extends Model
{
    protected $fillable = ['auction_id', 'name', 'condition', 'location'];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
