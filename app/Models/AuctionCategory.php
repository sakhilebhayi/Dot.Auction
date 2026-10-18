<?php

namespace App\Models;

use Database\Factories\AuctionCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionCategory extends Model
{
    /** @use HasFactory<AuctionCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class, 'category_id');
    }
}
