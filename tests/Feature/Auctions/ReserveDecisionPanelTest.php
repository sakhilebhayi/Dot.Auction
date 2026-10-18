<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ReserveDecisionPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): array
    {
        $seller = User::factory()->create();
        $bidder = User::factory()->create();

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Rare Coin',
            'starting_price' => 50,
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

        return compact('seller', 'bidder', 'auction', 'bid', 'proposal');
    }

    public function test_the_seller_can_accept_the_top_bid(): void
    {
        Notification::fake();
        ['seller' => $seller, 'bidder' => $bidder, 'auction' => $auction, 'proposal' => $proposal] = $this->makeProposal();

        Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept');

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertNotNull($proposal->fresh()->decided_at);
        Notification::assertSentTo($bidder, AuctionWonNotification::class);
    }

    public function test_the_seller_can_reject_the_top_bid(): void
    {
        Notification::fake();
        ['seller' => $seller, 'auction' => $auction, 'proposal' => $proposal] = $this->makeProposal();

        Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('reject');

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame('rejected', $proposal->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_non_seller_is_forbidden(): void
    {
        ['proposal' => $proposal] = $this->makeProposal();
        $someoneElse = User::factory()->create();

        Livewire::actingAs($someoneElse)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept')
            ->assertForbidden();

        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_accepting_a_proposal_that_was_already_rejected_does_nothing(): void
    {
        Notification::fake();
        ['seller' => $seller, 'auction' => $auction, 'proposal' => $proposal] = $this->makeProposal();

        $panel = Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal]);

        // Decided elsewhere (second tab / double submit) after this component hydrated.
        $proposal->update(['status' => 'rejected', 'decided_at' => now()]);
        $auction->update(['status' => 'ended']);

        $panel->call('accept');

        $this->assertSame('rejected', $proposal->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_accept_twice_notifies_the_winner_once(): void
    {
        Notification::fake();
        ['seller' => $seller, 'bidder' => $bidder, 'proposal' => $proposal] = $this->makeProposal();

        $panel = Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal]);
        $panel->call('accept');
        $panel->call('accept');

        $this->assertSame('accepted', $proposal->fresh()->status);
        Notification::assertSentToTimes($bidder, AuctionWonNotification::class, 1);
    }

    public function test_a_decided_proposal_cannot_be_flipped_by_the_opposite_action(): void
    {
        Notification::fake();
        ['seller' => $seller, 'proposal' => $proposal] = $this->makeProposal();

        $panel = Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal]);
        $panel->call('accept');
        $panel->call('reject');

        $this->assertSame('accepted', $proposal->fresh()->status);
    }

    public function test_rejecting_a_proposal_that_was_already_accepted_does_nothing(): void
    {
        ['seller' => $seller, 'auction' => $auction, 'proposal' => $proposal] = $this->makeProposal();

        $panel = Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal]);
        $proposal->update(['status' => 'accepted', 'decided_at' => now()]);

        $panel->call('reject');

        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame('pending_review', $auction->fresh()->status);
    }
}
