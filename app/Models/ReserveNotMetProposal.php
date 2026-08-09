<?php

namespace App\Models;

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
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }
}
