<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property bool $is_platform_operator
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_operator' => 'boolean',
        ];
    }

    /**
     * Platform operators run the Auction deployment itself (the payment-operations pages). This is
     * deliberately unrelated to any team role or to who sells or bids. Not mass assignable: it is
     * only ever set by `php artisan auction:operator`.
     */
    public function isPlatformOperator(): bool
    {
        return $this->is_platform_operator === true;
    }

    /**
     * Photo files of this user's auctions, to remove once the account delete
     * commits. The auction and image rows go through the database cascade,
     * which fires no model events, so nothing else would clean the files.
     *
     * @var array<int, string>
     */
    protected array $auctionImagePathsToRemove = [];

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $user->auctionImagePathsToRemove = AuctionImage::query()
                ->whereHas('auction', fn ($auctions) => $auctions->where('seller_id', $user->id))
                ->pluck('path')
                ->all();
        });

        static::deleted(function (User $user): void {
            $paths = $user->auctionImagePathsToRemove;

            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk(AuctionImage::DISK)->delete($paths));
            }
        });
    }

    /**
     * Auctions this user is watching (buyer-side interest tracking).
     */
    public function watchlist(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    /**
     * Bids this user has placed as a bidder.
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class, 'bidder_id');
    }

    /**
     * Auctions this user is selling.
     */
    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class, 'seller_id');
    }
}
