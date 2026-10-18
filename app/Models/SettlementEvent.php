<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail of what moved a settlement: type is paid | overdue | cancelled | payment_opened. */
class SettlementEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['settlement_id', 'user_id', 'type', 'note'];

    protected $casts = ['created_at' => 'datetime'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AuctionSettlement::class, 'settlement_id');
    }
}
