<?php

namespace Tests\Feature\R42;

use App\Events\ReserveNotMet;
use App\Listeners\NotifySellerOfReserveNotMet;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Notifications\ReserveNotMetNotification;
use App\Services\Auctions\AuctionSettler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * F-09 (the non-controversial part): the seller is told once; no deadline is invented.
 */
class ReserveNotMetNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function closedBelowReserve(): ReserveNotMetProposal
    {
        $auction = Auction::factory()->create([
            'reserve_price' => '500.00', 'current_price' => '300.00',
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute(),
        ]);
        $bid = Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => User::factory()->create()->id, 'amount' => '300.00', 'is_winning' => true]);

        app(AuctionSettler::class)->settle($auction);

        return ReserveNotMetProposal::sole();
    }

    public function test_closing_below_the_reserve_tells_the_seller_with_a_link_to_the_decision_panel(): void
    {
        $proposal = $this->closedBelowReserve();
        $seller = $proposal->auction->seller;

        $this->assertSame(1, $seller->notifications()->count());
        $data = $seller->notifications()->first()->data;
        $this->assertSame('reserve_not_met', $data['type']);
        $this->assertSame(route('dashboard'), $data['url']);
        $this->assertSame($proposal->id, $data['proposal_id']);
        $this->assertStringContainsString('R300.00', $data['message']);
        $this->assertStringNotContainsString('500', $data['message'], 'the reserve is the seller\'s own number, but it is not needed in a notice');
        $this->assertNotNull($proposal->fresh()->seller_notified_at);
    }

    public function test_redelivering_the_event_does_not_notify_twice(): void
    {
        Notification::fake();
        $proposal = $this->closedBelowReserve();
        $listener = app(NotifySellerOfReserveNotMet::class);

        $listener->handle(new ReserveNotMet($proposal));
        $listener->handle(new ReserveNotMet($proposal));
        $listener->handle(new ReserveNotMet($proposal->fresh()));

        Notification::assertSentToTimes($proposal->auction->seller, ReserveNotMetNotification::class, 1);
    }

    public function test_a_failed_send_releases_the_claim_so_the_retry_can_notify(): void
    {
        $proposal = $this->closedBelowReserve();
        $proposal->forceFill(['seller_notified_at' => null])->save();

        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            if ($event->notification instanceof ReserveNotMetNotification) {
                throw new \RuntimeException('channel down');
            }
        });

        try {
            app(NotifySellerOfReserveNotMet::class)->handle(new ReserveNotMet($proposal));
            $this->fail('the failure should reach the queue so it retries');
        } catch (\RuntimeException) {
            $this->assertNull($proposal->fresh()->seller_notified_at, 'the claim must be released');
        }
    }

    public function test_a_decided_proposal_is_not_announced(): void
    {
        $proposal = $this->closedBelowReserve();
        $proposal->forceFill(['status' => 'rejected', 'seller_notified_at' => null])->save();
        Notification::fake();

        app(NotifySellerOfReserveNotMet::class)->handle(new ReserveNotMet($proposal));

        Notification::assertNothingSent();
    }

    public function test_awaiting_since_is_the_creation_time_while_pending_and_null_after_the_decision(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $proposal = $this->closedBelowReserve();

        $this->assertTrue($proposal->awaiting_since->equalTo(Carbon::parse('2026-11-08 10:00:00')));

        $proposal->forceFill(['status' => 'accepted'])->save();
        $this->assertNull($proposal->fresh()->awaiting_since);

        Carbon::setTestNow();
    }

    public function test_the_email_event_is_off_until_notify_has_it_in_its_catalogue(): void
    {
        $proposal = $this->closedBelowReserve();
        $notification = new ReserveNotMetNotification($proposal);

        config(['notify.reserve_not_met_event' => false]);
        $this->assertNull($notification->toNotifyEvent($proposal->auction->seller));

        config(['notify.reserve_not_met_event' => true]);
        $event = $notification->toNotifyEvent($proposal->auction->seller);
        $this->assertSame('auction.reserve_not_met', $event['event']);
        $this->assertSame($proposal->id, $event['bucket']);
        $this->assertSame('300.00', $event['data']['current_price']);
        $this->assertArrayNotHasKey('reserve_price', $event['data']);
        Http::assertNothingSent();
    }
}
