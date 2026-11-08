<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReserveNotMetProposal extends Model
{
    protected $fillable = [
        'auction_id', 'bid_id', 'reserve_price', 'status', 'decided_at',
    ];

    protected $casts = [
        'reserve_price' => 'decimal:2',
        'decided_at' => 'datetime',
        'seller_notified_at' => 'datetime',
    ];

    /**
     * When the seller was handed this decision: the moment the auction closed below its reserve. There is deliberately
     * no deadline (R-42 F-09 needs owner rules), so this is only what a screen can show as "waiting since" or "waiting
     * N days". Null for a proposal that has been decided.
     */
    protected function awaitingSince(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'pending' ? $this->created_at : null);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return BelongsTo<Bid, $this>
     */
    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }
}
