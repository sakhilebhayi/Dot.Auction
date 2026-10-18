<?php

namespace App\Models;

use Database\Factories\AuctionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Auction extends Model
{
    /** @use HasFactory<AuctionFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_id', 'category_id', 'title', 'description', 'image_path',
        'starting_price', 'reserve_price', 'current_price', 'buy_now_price',
        'bid_increment', 'status', 'starts_at', 'ends_at', 'snipe_extensions',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'starting_price' => 'decimal:2',
        'current_price' => 'decimal:2',
        'reserve_price' => 'decimal:2',
        'buy_now_price' => 'decimal:2',
        'bid_increment' => 'decimal:2',
        'snipe_extensions' => 'integer',
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

    /**
     * Files to remove once a delete commits. The image rows go with the
     * auction through the database cascade, which fires no model events, so
     * the paths are captured just before the delete.
     *
     * @var array<int, string>
     */
    protected array $imagePathsToRemove = [];

    protected static function booted(): void
    {
        static::deleting(function (Auction $auction): void {
            $auction->imagePathsToRemove = $auction->images()->pluck('path')->all();
        });

        static::deleted(function (Auction $auction): void {
            $paths = $auction->imagePathsToRemove;

            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk(AuctionImage::DISK)->delete($paths));
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * @return BelongsTo<AuctionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AuctionCategory::class);
    }

    /**
     * @return HasMany<Bid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class)->latest();
    }

    /**
     * @return HasOne<Bid, $this>
     */
    public function winningBid(): HasOne
    {
        return $this->hasOne(Bid::class)->where('is_winning', true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuctionItem::class);
    }

    /**
     * Photos in display order; the first is the cover.
     *
     * @return HasMany<AuctionImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(AuctionImage::class)->orderBy('position')->orderBy('id');
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
     * Published ('active' in the status model) but not yet open for bids.
     */
    public function isScheduled(): bool
    {
        return $this->status === 'active' && $this->starts_at->isFuture();
    }

    public function hasBids(): bool
    {
        return $this->bids()->exists();
    }

    /**
     * The state a seller sees: the stored status, except that a published
     * auction whose start time has not arrived reads as 'scheduled'.
     */
    public function displayStatus(): string
    {
        return $this->isScheduled() ? 'scheduled' : $this->status;
    }

    /**
     * Whether the Buy Now option can be taken right now, judged against the
     * hydrated copy (display only -- BuyNowPurchaser re-checks under lock).
     * It disappears once the price has reached the Buy Now amount, and when
     * a reserve sits above it (the two would contradict each other).
     */
    public function buyNowAvailable(): bool
    {
        if ($this->buy_now_price === null || ! $this->isActive()) {
            return false;
        }

        $buyNow = self::toCents($this->buy_now_price);

        return self::toCents($this->current_price) < $buyNow
            && ($this->reserve_price === null || self::toCents($this->reserve_price) <= $buyNow);
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
     * Integer cents back to the 2-decimal string the decimal(12,2) columns
     * take, with no float involved.
     */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
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
