<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Auction extends Model
{
    protected $fillable = [
        'seller_id', 'category_id', 'title', 'description', 'image_path',
        'starting_price', 'reserve_price', 'current_price', 'buy_now_price',
        'bid_increment', 'status', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'starting_price' => 'decimal:2',
        'current_price' => 'decimal:2',
        'reserve_price' => 'decimal:2',
        'buy_now_price' => 'decimal:2',
        'bid_increment' => 'decimal:2',
    ];

    /**
     * Confidentiality was previously enforced only by convention (no Blade
     * template happens to print reserve_price). $hidden makes it structural:
     * blocked from array()/toJson()/wire: serialization regardless of future
     * template changes. Direct PHP attribute access (Blade's
     * $auction->reserve_price on the seller's own dashboard) is unaffected —
     * $hidden only governs serialization, per Eloquent's documented behavior.
     */
    protected $hidden = ['reserve_price'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AuctionCategory::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class)->latest();
    }

    public function winningBid(): HasOne
    {
        return $this->hasOne(Bid::class)->where('is_winning', true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuctionItem::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && now()->between($this->starts_at, $this->ends_at);
    }

    /**
     * Convert a money value to integer cents without float rounding drift.
     * Strings (what the decimal:2 casts return) are parsed digit by digit;
     * floats/ints (user input) are rounded to the nearest cent.
     */
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null) {
            return 0;
        }

        if (is_string($amount) && preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($amount), $m)) {
            $cents = ((int) $m[2]) * 100 + (int) str_pad(substr($m[3] ?? '', 0, 2), 2, '0');

            return $m[1] === '-' ? -$cents : $cents;
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * Format an amount with the app's single configured currency symbol.
     */
    public static function money(string|int|float|null $amount): string
    {
        return config('auction.currency_symbol', 'R').number_format(self::toCents($amount) / 100, 2);
    }

    public function minimumBidCents(): int
    {
        return self::toCents($this->current_price) + self::toCents($this->bid_increment);
    }

    public function minimumBid(): float
    {
        return $this->minimumBidCents() / 100;
    }

    /**
     * Whether the reserve has been met (or there is no reserve), without
     * ever exposing the reserve amount itself. Per Dot.Brain's mechanism
     * scoping rules (wiki.md §5), reserve prices are confidential — only
     * met/not-met should ever be surfaced to a buyer, never the value.
     */
    public function reserveMet(): bool
    {
        if ($this->reserve_price === null) {
            return true;
        }

        return self::toCents($this->current_price) >= self::toCents($this->reserve_price);
    }

    /**
     * Whether the given user is allowed to see this auction's raw reserve
     * price (the seller only). Used by seller-facing views; buyer-facing
     * views should call reserveMet() instead and never read reserve_price.
     */
    public function reserveVisibleTo(?User $user): bool
    {
        return $user !== null && $user->id === $this->seller_id;
    }

    public function endsWithin(int $hours): bool
    {
        return $this->isActive() && now()->addHours($hours)->greaterThanOrEqualTo($this->ends_at);
    }

    public function scopeBrowsable($query)
    {
        return $query->where('status', '!=', 'draft');
    }

    public function scopeEndingSoon($query, int $hours = 24)
    {
        return $query->where('status', 'active')
            ->whereBetween('ends_at', [now(), now()->addHours($hours)]);
    }
}
