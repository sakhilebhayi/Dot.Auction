<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbox row for the payment platform: one per sold auction, written by
 * App\Listeners\RecordSettlementForPayment. Dot.Auction creates it in
 * `awaiting_payment` and never moves it on; whichever payment platform is
 * eventually chosen is expected to read these rows and own every status
 * change after that. Amounts are integer minor units (cents) in `currency`.
 */
class AuctionSettlement extends Model
{
    use HasUuids;

    public const AWAITING_PAYMENT = 'awaiting_payment';

    protected $fillable = [
        'auction_id', 'winning_bid_id', 'seller_id', 'winner_id',
        'amount_cents', 'currency', 'status',
    ];

    protected $casts = ['amount_cents' => 'integer'];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function amount(): string
    {
        return Auction::money($this->amount_cents / 100);
    }
}
