<?php

namespace Tests\Feature\Security;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionImage;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\ViewerActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * No page may ask the database more questions because there is more data. Each page is opened with a small world and again
 * with the same person involved in ten times as many lots, bids, watches, sales, photos and notifications; the number of
 * queries must not move. A page that starts to loop over rows (an N+1) fails here the day it is written.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** Hard ceiling per page, whatever the data: far above today's count, low enough to catch a runaway. */
    private const CEILING = 40;

    private User $me;

    private User $operator;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->withPersonalTeam()->create(['name' => 'Budget Person']);
        $this->operator = User::factory()->withPersonalTeam()->platformOperator()->create();
    }

    /** Make the signed-in person a seller, bidder, watcher and winner on $lots more lots each. */
    private function grow(int $lots): void
    {
        $category = AuctionCategory::factory()->create();
        $other = User::factory()->withPersonalTeam()->create();

        for ($i = 0; $i < $lots; $i++) {
            $n = ++$this->serial;

            // A lot I sell, with photos and a bid from someone else.
            $mine = Auction::factory()->create(['seller_id' => $this->me->id, 'category_id' => $category->id, 'title' => "Mine {$n}"]);
            $this->photos($mine);
            Bid::forceCreate(['auction_id' => $mine->id, 'bidder_id' => $other->id, 'amount' => '250.00', 'is_winning' => true]);
            $mine->forceFill(['current_price' => '250.00'])->save();

            // A lot I am bidding on, once leading and once outbid.
            $theirs = Auction::factory()->create(['seller_id' => $other->id, 'category_id' => $category->id, 'title' => "Theirs {$n}"]);
            $this->photos($theirs);
            Bid::forceCreate(['auction_id' => $theirs->id, 'bidder_id' => $this->me->id, 'amount' => '210.00', 'is_winning' => $n % 2 === 0]);
            if ($n % 2 === 1) {
                Bid::forceCreate(['auction_id' => $theirs->id, 'bidder_id' => $other->id, 'amount' => '230.00', 'is_winning' => true]);
            }
            Watchlist::forceCreate(['user_id' => $this->me->id, 'auction_id' => $theirs->id]);

            // A lot I won, with its sale and a payment attempt.
            $won = Auction::factory()->ended()->create(['seller_id' => $other->id, 'category_id' => $category->id, 'status' => 'ended', 'title' => "Won {$n}"]);
            $this->photos($won);
            $bid = Bid::forceCreate(['auction_id' => $won->id, 'bidder_id' => $this->me->id, 'amount' => '300.00', 'is_winning' => true]);
            $sale = AuctionSettlement::forceCreate(['auction_id' => $won->id, 'winning_bid_id' => $bid->id, 'seller_id' => $other->id, 'winner_id' => $this->me->id, 'amount_cents' => 30000, 'currency' => 'ZAR', 'status' => 'awaiting_payment', 'payment_due_at' => now()->addDay()]);
            SettlementPayment::forceCreate(['settlement_id' => $sale->id, 'provider' => 'paystack', 'reference' => 'AUC-BUDGET'.$n, 'amount_cents' => 30000, 'currency' => 'ZAR', 'status' => $n % 2 ? 'unmatched' : 'paid']);

            // A lot I sold, and a notification.
            $sold = Auction::factory()->ended()->create(['seller_id' => $this->me->id, 'category_id' => $category->id, 'status' => 'ended', 'title' => "Sold {$n}"]);
            $soldBid = Bid::forceCreate(['auction_id' => $sold->id, 'bidder_id' => $other->id, 'amount' => '400.00', 'is_winning' => true]);
            AuctionSettlement::forceCreate(['auction_id' => $sold->id, 'winning_bid_id' => $soldBid->id, 'seller_id' => $this->me->id, 'winner_id' => $other->id, 'amount_cents' => 40000, 'currency' => 'ZAR', 'status' => 'payment_overdue', 'payment_due_at' => now()->subDay()]);

            $this->me->notify(new OutbidNotification($soldBid));
        }
    }

    private function photos(Auction $auction): void
    {
        foreach ([0, 1] as $position) {
            AuctionImage::create(['auction_id' => $auction->id, 'path' => "{$auction->id}/x{$position}.jpg", 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'width' => 40, 'height' => 30, 'position' => $position]);
        }
    }

    private function queries(User $as, string $url): int
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($as);
        $this->get($url)->assertOk(); // warm: compiled views, config

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_no_page_asks_more_questions_when_there_is_ten_times_the_data(): void
    {
        $pages = [
            ['me', '/dashboard'], ['me', '/auctions'], ['me', '/auctions?state=live&sort=bids'], ['me', '/auctions?q=Theirs'], ['me', '/bids'], ['me', '/watchlist'], ['me', '/won'],
            ['me', '/seller/auctions'], ['me', '/seller/auctions?state=ending'], ['me', '/settlements'], ['me', '/notifications'],
            ['operator', '/operator/settlement-payments'], ['me', '/user/profile'],
        ];

        $this->grow(3);
        $small = [];
        foreach ($pages as [$who, $url]) {
            $small[$url] = $this->queries($who === 'me' ? $this->me : $this->operator, $url);
        }

        $this->grow(27); // ten times as much, and more than one page of every list
        foreach ($pages as [$who, $url]) {
            $large = $this->queries($who === 'me' ? $this->me : $this->operator, $url);

            $this->assertSame($small[$url], $large, "{$url}: {$small[$url]} queries with 3 lots, {$large} with 30. Something loads per row.");
            $this->assertLessThanOrEqual(self::CEILING, $large, "{$url} runs {$large} queries");
        }
    }

    public function test_a_lot_page_costs_the_same_with_ten_bids_or_a_hundred(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '1.00']);
        $bidders = User::factory()->count(5)->create();
        $this->photos($lot);

        $add = function (int $from, int $to) use ($lot, $bidders) {
            for ($i = $from; $i <= $to; $i++) {
                Bid::query()->where('auction_id', $lot->id)->where('is_winning', true)->update(['is_winning' => false]);
                Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $bidders[$i % 5]->id, 'amount' => (string) (100 + $i), 'is_winning' => true]);
            }
            $lot->forceFill(['current_price' => (string) (100 + $to)])->save();
        };

        $add(1, 10);
        $few = $this->queries($this->me, '/auctions/'.$lot->id);
        $add(11, 100);
        $many = $this->queries($this->me, '/auctions/'.$lot->id);

        $this->assertSame($few, $many, 'the lot page runs more queries as bids pile up');
        $this->assertLessThanOrEqual(self::CEILING, $many);
    }

    public function test_the_bid_panel_poll_is_a_handful_of_queries_and_one_row_of_work(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create(['seller_id' => $seller->id]);
        $this->actingAs($this->me);

        $panel = Livewire::test(BidPanel::class, ['auction' => $lot]);
        $panel->call('snapshot'); // fill the one-second cache the way a first viewer does

        DB::flushQueryLog();
        DB::enableQueryLog();
        $panel->call('snapshot');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The component is rebuilt for every call (the lot is re-read); the snapshot itself is served from the cache.
        $this->assertLessThanOrEqual(4, $queries, "a poll ran {$queries} queries");
    }

    public function test_pages_that_are_not_paged_stop_at_a_ceiling(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $over = ViewerActivity::SAFETY_CEILING + 15;

        for ($i = 0; $i < $over; $i++) {
            $lot = Auction::factory()->create(['seller_id' => $other->id]);
            Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $this->me->id, 'amount' => '210.00', 'is_winning' => true]);
        }

        $this->assertCount(ViewerActivity::SAFETY_CEILING, app(ViewerActivity::class)->openBids($this->me));
    }
}
