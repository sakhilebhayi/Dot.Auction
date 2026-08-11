<?php

namespace App\Events;

use App\Models\Bid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BidPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Bid $bid) {}

    /**
     * Public, not private: AuctionPolicy::view() already treats a
     * published auction (and its bids) as visible to any authenticated
     * user, matching a public marketplace listing -- there's nothing this
     * channel needs to gate that the HTTP page doesn't already show.
     */
    public function broadcastOn(): array
    {
        return [new Channel('auction.'.$this->bid->auction_id)];
    }

    /**
     * Short, explicit wire name. Without this, the default (the fully
     * qualified class name) requires a frontend listener to match that
     * exact string -- easy to get wrong, and the class name isn't a
     * contract anyone should have to depend on. The listener still needs
     * a leading "." (see resources/views/livewire/auctions/bid-panel.blade.php)
     * for Echo to use this name unnamespaced. See
     * docs/DOT_REALTIME_STANDARD.md (Dot.Mines) §5.
     */
    public function broadcastAs(): string
    {
        return 'bid.placed';
    }

    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->bid->auction_id,
            'amount' => $this->bid->amount,
            'bidder' => $this->bid->bidder->name,
            'current_price' => $this->bid->auction->current_price,
        ];
    }
}
