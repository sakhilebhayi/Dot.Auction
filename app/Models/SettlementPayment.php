<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Paystack payment attempt for a settlement, or a payment event we could not match to one
 * (status `unmatched`, kept for an operator). `reference` is ours and unique. Amounts are integer
 * cents. The checkout link is a bearer link to a payment page: it is hidden from serialization and
 * is never logged or listed.
 */
class SettlementPayment extends Model
{
    public const INITIATED = 'initiated';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const UNMATCHED = 'unmatched';

    protected $fillable = [
        'settlement_id', 'provider', 'reference', 'amount_cents', 'currency', 'status', 'reason',
        'provider_transaction_id', 'authorization_url', 'initiated_by', 'expires_at', 'paid_at', 'summary',
    ];

    protected $hidden = ['authorization_url'];

    protected $casts = [
        'amount_cents' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AuctionSettlement::class, 'settlement_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::INITIATED
            && $this->authorization_url !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function needsAnOperator(): bool
    {
        return $this->status === self::UNMATCHED;
    }

    public function reasonLabel(): ?string
    {
        return $this->reason === null ? null : ucfirst(str_replace('_', ' ', $this->reason));
    }
}
