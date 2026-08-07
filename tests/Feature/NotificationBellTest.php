<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_notification_bell_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeLivewire('notification-bell');
    }

    public function test_unread_count_reflects_database_notifications(): void
    {
        $seller = User::factory()->create();
        $bidder = User::factory()->withPersonalTeam()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Outbid Test Lot',
            'starting_price' => 10,
            'current_price' => 30,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 30,
            'is_winning' => true,
        ]);

        $bidder->notify(new OutbidNotification($bid));

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($bidder)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee("You've been outbid");

        $this->assertEquals(1, $bidder->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $seller = User::factory()->create();
        $bidder = User::factory()->withPersonalTeam()->create();
        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Outbid Test Lot Two',
            'starting_price' => 10,
            'current_price' => 30,
            'bid_increment' => 5,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 30,
            'is_winning' => true,
        ]);

        $bidder->notify(new OutbidNotification($bid));

        Livewire::actingAs($bidder)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $bidder->fresh()->unreadNotifications()->count());
    }
}
