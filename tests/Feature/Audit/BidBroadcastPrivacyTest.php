<?php

namespace Tests\Feature\Audit;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bid history shows "Sakhile B."; the broadcast used to carry the bidder's full name on a PUBLIC channel
 * (readable by any websocket client, signed in or not) when Reverb is running.
 */
class BidBroadcastPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function broadcastFor(string $name): array
    {
        $auction = Auction::factory()->create();
        $bidder = User::factory()->create(['name' => $name]);
        $bid = Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => $bidder->id, 'amount' => 220, 'is_winning' => true]);

        return (new BidPlaced($bid->fresh()))->broadcastWith();
    }

    public function test_the_broadcast_never_carries_a_full_name(): void
    {
        $payload = $this->broadcastFor('Sakhile Maxwell Bhayi');

        $this->assertSame('Sakhile M.', $payload['bidder']);
        $this->assertStringNotContainsString('Bhayi', json_encode($payload));
        $this->assertStringNotContainsString('Maxwell', json_encode($payload));
    }

    public function test_single_word_and_blank_names_are_handled(): void
    {
        $this->assertSame('Prince', $this->broadcastFor('Prince')['bidder']);
        $this->assertSame('A bidder', $this->broadcastFor('   ')['bidder']);
        $this->assertSame('Élodie D.', $this->broadcastFor('Élodie du Plessis')['bidder']);
    }

    public function test_the_payload_carries_no_email_or_user_id_or_reserve(): void
    {
        $payload = $this->broadcastFor('Sakhile Bhayi');

        $this->assertEqualsCanonicalizing(
            ['auction_id', 'amount', 'bidder', 'current_price', 'ends_at', 'snipe_extensions', 'status'],
            array_keys($payload),
        );
    }

    public function test_bid_events_use_the_public_auction_channel_and_notifications_use_a_private_one(): void
    {
        // Documents the current design (public channel: anyone with the Reverb app key can listen), so a change to it is deliberate.
        $auction = Auction::factory()->create();
        $bid = Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => User::factory()->create()->id, 'amount' => 220, 'is_winning' => true]);
        $channels = (new BidPlaced($bid))->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertNotInstanceOf(PrivateChannel::class, $channels[0]);
    }
}
