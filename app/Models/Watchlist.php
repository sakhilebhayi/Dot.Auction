<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `watchlists` table (buyer interest tracking).
 * The table has existed since the original auction schema migration, but
 * had no model class — added here so the buyer-facing watchlist toggle
 * (platform-loop pass) has something to query/create against.
 *
 * A watchlist is genuinely private to the owning user — unlike Auction/
 * Bid, there is no legitimate reason for one user's query to ever surface
 * another user's watchlist rows. HasUserScope applies a global scope so
 * that holds structurally, not just by convention (every existing call
 * site already remembered to add where('user_id', auth()->id()), but a
 * future one might not).
 */
class Watchlist extends Model
{
    use HasUserScope;

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
