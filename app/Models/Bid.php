<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    /**
     * Nothing is mass assignable: a bid is written only by BidPlacer and BuyNowPurchaser (forceCreate), inside the
     * auction's row lock.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected $casts = [
        'is_winning' => 'boolean',
        'is_auto_bid' => 'boolean',
        'amount' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bidder_id');
    }
}
