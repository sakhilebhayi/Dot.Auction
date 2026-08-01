<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `watchlists` table (buyer interest tracking).
 * The table has existed since the original auction schema migration, but
 * had no model class — added here so the buyer-facing watchlist toggle
 * (platform-loop pass) has something to query/create against.
 */
class Watchlist extends Model
{
    protected $fillable = ['user_id', 'auction_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
