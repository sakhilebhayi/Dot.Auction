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

    public function broadcastOn(): array
    {
        return [new Channel('auction.'.$this->bid->auction_id)];
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
