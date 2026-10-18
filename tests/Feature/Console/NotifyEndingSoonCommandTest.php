<?php

namespace Tests\Feature\Console;

use App\Models\Auction;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\AuctionEndingSoonNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyEndingSoonCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auction.ending_soon_window_minutes' => 60]);
    }

    private function watcher(Auction $auction, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        Watchlist::withoutGlobalScopes()->create(['user_id' => $user->id, 'auction_id' => $auction->id]);

        return $user;
    }

    private function sweep(): void
    {
        $this->artisan('auction:notify-ending-soon')->assertSuccessful();
    }

    public function test_each_watcher_of_an_auction_ending_soon_is_notified_once(): void
    {
        Notification::fake();
        $auction = Auction::factory()->endingIn(30)->create();
        [$a, $b] = [$this->watcher($auction), $this->watcher($auction)];

        $this->sweep();

        Notification::assertSentToTimes($a, AuctionEndingSoonNotification::class, 1);
        Notification::assertSentToTimes($b, AuctionEndingSoonNotification::class, 1);
        Notification::assertSentTo($a, AuctionEndingSoonNotification::class, fn ($n) => $n->auction->is($auction));
    }

    public function test_running_again_does_not_notify_twice(): void
    {
        Notification::fake();
        $auction = Auction::factory()->endingIn(30)->create();
        $watcher = $this->watcher($auction);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        Notification::assertSentToTimes($watcher, AuctionEndingSoonNotification::class, 1);
    }

    public function test_a_watcher_added_between_runs_is_notified_without_repeating_the_first(): void
    {
        Notification::fake();
        $auction = Auction::factory()->endingIn(30)->create();
        $early = $this->watcher($auction);
        $this->sweep();
        $late = $this->watcher($auction);

        $this->sweep();

        Notification::assertSentToTimes($early, AuctionEndingSoonNotification::class, 1);
        Notification::assertSentToTimes($late, AuctionEndingSoonNotification::class, 1);
    }

    public function test_a_watcher_gets_one_notification_per_auction(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $first = Auction::factory()->endingIn(10)->create();
        $second = Auction::factory()->endingIn(40)->create();
        $this->watcher($first, $user);
        $this->watcher($second, $user);

        $this->sweep();
        $this->sweep();

        Notification::assertSentToTimes($user, AuctionEndingSoonNotification::class, 2);
    }

    public function test_only_live_auctions_inside_the_window_are_considered(): void
    {
        Notification::fake();
        $far = Auction::factory()->endingIn(61)->create();
        $closed = Auction::factory()->create(['ends_at' => now()->subMinute()]);
        $ended = Auction::factory()->ended()->create();
        $cancelled = Auction::factory()->endingIn(20)->create(['status' => 'cancelled']);
        $draft = Auction::factory()->endingIn(20)->create(['status' => 'draft']);
        $unopened = Auction::factory()->endingIn(20)->create(['starts_at' => now()->addMinutes(5)]);
        $watchers = collect([$far, $closed, $ended, $cancelled, $draft, $unopened])
            ->map(fn ($auction) => $this->watcher($auction));

        $this->sweep();

        foreach ($watchers as $watcher) {
            Notification::assertNotSentTo($watcher, AuctionEndingSoonNotification::class);
        }
    }

    public function test_an_auction_exactly_at_the_edge_of_the_window_is_included(): void
    {
        Notification::fake();
        $auction = Auction::factory()->endingIn(60)->create();
        $watcher = $this->watcher($auction);

        $this->sweep();

        Notification::assertSentTo($watcher, AuctionEndingSoonNotification::class);
    }

    public function test_non_watchers_and_the_seller_are_not_notified(): void
    {
        Notification::fake();
        $auction = Auction::factory()->endingIn(30)->create();
        $bystander = User::factory()->create();
        $this->watcher($auction, $auction->seller);

        $this->sweep();

        Notification::assertNotSentTo($bystander, AuctionEndingSoonNotification::class);
        Notification::assertNotSentTo($auction->seller, AuctionEndingSoonNotification::class);
    }

    public function test_the_notification_lands_in_the_database_exactly_once(): void
    {
        $auction = Auction::factory()->endingIn(30)->create();
        $watcher = $this->watcher($auction);

        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $watcher->notifications()->count());
        $this->assertSame('auction_ending_soon', $watcher->notifications()->first()->data['type']);
        $this->assertSame($auction->id, $watcher->notifications()->first()->data['auction_id']);
    }

    public function test_the_command_is_scheduled_next_to_the_settlement_sweep(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'auction:settle-ended')));
        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'auction:notify-ending-soon')));
    }
}
