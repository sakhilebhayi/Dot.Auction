<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One per sold auction, written by App\Listeners\RecordSettlementForPayment in `awaiting_payment`.
 * Amounts are integer minor units (cents) in `currency`; the amount a winner must pay is always this
 * row's `amount_cents`, never anything a browser sent.
 *
 * Lifecycle: awaiting_payment -> paid (a verified Paystack success) | payment_overdue (past
 * payment_due_at, still payable) -> paid | cancelled (the seller's decision). A paid settlement is
 * final. Paid means the winner's money reached the PLATFORM's Paystack balance; paying the seller out
 * is not built and stays a manual step.
 */
class AuctionSettlement extends Model
{
    use HasUuids;

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const PAID = 'paid';

    public const PAYMENT_OVERDUE = 'payment_overdue';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'auction_id', 'winning_bid_id', 'seller_id', 'winner_id',
        'amount_cents', 'currency', 'status',
        'payment_due_at', 'paid_at', 'overdue_at', 'cancelled_at', 'cancelled_by',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'payment_due_at' => 'datetime',
        'paid_at' => 'datetime',
        'overdue_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

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

    public function payments(): HasMany
    {
        return $this->hasMany(SettlementPayment::class, 'settlement_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SettlementEvent::class, 'settlement_id');
    }

    public function amount(): string
    {
        return Auction::money($this->amount_cents / 100);
    }

    /** Still owed: the winner may pay (an overdue sale is payable until the seller cancels it). */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::AWAITING_PAYMENT, self::PAYMENT_OVERDUE], true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isWinner(?User $user): bool
    {
        return $user !== null && $user->getKey() === $this->winner_id;
    }

    public function isSeller(?User $user): bool
    {
        return $user !== null && $user->getKey() === $this->seller_id;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::AWAITING_PAYMENT => 'Awaiting payment',
            self::PAID => 'Paid',
            self::PAYMENT_OVERDUE => 'Payment overdue',
            self::CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }
}
