<?php

namespace Tests\Feature\Audit;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * bids.created_at is a whole-second timestamp, so bids placed in the same second used to come back in an
 * unspecified order and the history could show an older bid above a newer one.
 */
class BidHistoryOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_bids_in_the_same_second_are_listed_newest_first_by_id(): void
    {
        $auction = Auction::factory()->create();
        $second = now()->startOfSecond();
        $ids = [];

        foreach ([210, 220, 230, 240, 250] as $amount) {
            $ids[] = Bid::forceCreate([
                'auction_id' => $auction->id,
                'bidder_id' => User::factory()->create()->id,
                'amount' => $amount,
                'is_winning' => false,
                'created_at' => $second,
                'updated_at' => $second,
            ]);
        }

        $this->assertSame(
            array_reverse(array_map(fn (Bid $b) => $b->id, $ids)),
            $auction->bids()->pluck('id')->all(),
        );
    }

    public function test_the_bid_panel_history_shows_the_highest_of_a_same_second_burst_first(): void
    {
        $auction = Auction::factory()->create();
        $second = now()->startOfSecond();

        foreach ([210, 220, 230] as $amount) {
            Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => User::factory()->create()->id, 'amount' => $amount, 'is_winning' => false, 'created_at' => $second, 'updated_at' => $second]);
        }

        $viewer = User::factory()->withPersonalTeam()->create();

        $text = preg_replace('/\s+/', ' ', strip_tags(Livewire::actingAs($viewer)->test(BidPanel::class, ['auction' => $auction])->html()));
        // The money component sets symbol, whole and cents in separate spans; as text they read R230.00.
        $this->assertTrue(strpos($text, 'R230.00') < strpos($text, 'R220.00', strpos($text, 'R230.00')) && strpos($text, 'R220.00') < strpos($text, 'R210.00'));
    }
}
