<?php

namespace Tests\Unit\Models;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveNotMetProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_auction_and_a_bid(): void
    {
        $seller = User::factory()->create();
        $bidder = User::factory()->create();

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Vintage Camera',
            'starting_price' => 100,
            'reserve_price' => 500,
            'current_price' => 300,
            'bid_increment' => 10,
            'status' => 'pending_review',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subMinute(),
        ]);

        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 300,
            'is_winning' => true,
        ]);

        $proposal = ReserveNotMetProposal::create([
            'auction_id' => $auction->id,
            'bid_id' => $bid->id,
            'reserve_price' => 500,
            'status' => 'pending',
        ]);

        $this->assertTrue($proposal->auction->is($auction));
        $this->assertTrue($proposal->bid->is($bid));
        $this->assertSame('pending', $proposal->status);
    }
}
