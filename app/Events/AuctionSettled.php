<?php

namespace App\Events;

use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuctionSettled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Auction $auction,
        public readonly ?Bid $winningBid,
    ) {}
}
