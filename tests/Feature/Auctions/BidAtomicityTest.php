<?php

namespace Tests\Feature\Auctions;

use App\Events\BidPlaced;
use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SQLite cannot demonstrate row-lock behaviour (it serialises writers and
 * ignores FOR UPDATE), so "concurrency" here is simulated the way a race
 * actually manifests: a client holding a STALE model/component (loaded
 * before a competing bid committed) submits afterwards. Correct code must
 * re-read price/status inside its own transaction and reject the stale bid.
 */
class BidAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAuction(array $overrides = []): Auction
    {
        $seller = User::factory()->create();

        return Auction::create(array_merge([
            'seller_id' => $seller->id,
            'title' => 'Signed Guitar',
            'starting_price' => 200,
            'current_price' => 200,
            'bid_increment' => 20,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ], $overrides));
    }

    private function buyer(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function winnerCount(Auction $auction): int
    {
        return Bid::where('auction_id', $auction->id)->where('is_winning', true)->count();
    }

    public function test_a_stale_model_cannot_place_a_lower_bid_over_a_higher_one(): void
    {
        $auction = $this->makeAuction();
        [$a, $b] = [$this->buyer(), $this->buyer()];
        $placer = app(BidPlacer::class);

        $stale = Auction::find($auction->id); // price 200, so it thinks 220 is legal
        $placer->place($auction, $b, 240);

        try {
            $placer->place($stale, $a, 220);
            $this->fail('A stale lower bid must be rejected.');
        } catch (BidRejected $e) {
            $this->assertStringContainsString('Minimum bid is R260.00', $e->getMessage());
        }

        $this->assertSame(1, Bid::count());
        $this->assertSame(1, $this->winnerCount($auction));
        $this->assertEquals(240, $auction->fresh()->current_price);
        $this->assertSame($b->id, $auction->winningBid()->first()->bidder_id);
    }

    public function test_two_stale_panels_end_with_exactly_one_winner_and_a_consistent_price(): void
    {
        $auction = $this->makeAuction();
        [$a, $b] = [$this->buyer(), $this->buyer()];

        $panelA = Livewire::actingAs($a)->test(BidPanel::class, ['auction' => $auction]);
        $panelB = Livewire::actingAs($b)->test(BidPanel::class, ['auction' => $auction]);

        $this->actingAs($a);
        $panelA->set('bidAmount', 220)->call('placeBid')->assertSet('success', 'Bid placed successfully!');
        $this->actingAs($b);
        // B's component was hydrated at price 200 and still believes 220 is legal.
        $panelB->set('bidAmount', 220)->call('placeBid')
            ->assertSet('success', null)
            ->assertSet('error', 'Minimum bid is R240.00');

        $this->assertSame(1, Bid::count());
        $this->assertSame(1, $this->winnerCount($auction));
        $this->assertEquals(220, $auction->fresh()->current_price);

        $panelB->set('bidAmount', 240)->call('placeBid')->assertSet('success', 'Bid placed successfully!');

        $this->assertSame(2, Bid::count());
        $this->assertSame(1, $this->winnerCount($auction));
        $this->assertEquals(240, $auction->fresh()->current_price);
        $this->assertSame($b->id, $auction->winningBid()->first()->bidder_id);
    }

    public function test_a_bid_after_ends_at_is_rejected_even_though_status_is_still_active(): void
    {
        $auction = $this->makeAuction();
        $buyer = $this->buyer();

        $panel = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction]);
        // Settlement has not run yet, but the window closed after the page loaded.
        $auction->update(['ends_at' => now()->subMinute()]);

        $panel->set('bidAmount', 220)->call('placeBid')
            ->assertSet('error', 'This auction is no longer active.');

        $this->assertSame(0, Bid::count());
        $this->assertEquals(200, $auction->fresh()->current_price);
    }

    public function test_a_bid_on_an_auction_ended_after_page_load_is_rejected(): void
    {
        $auction = $this->makeAuction();
        $buyer = $this->buyer();

        $panel = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction]);
        $auction->update(['status' => 'ended']);

        $panel->set('bidAmount', 220)->call('placeBid')
            ->assertSet('error', 'This auction is no longer active.');

        $this->assertSame(0, Bid::count());
    }

    public function test_an_auction_that_has_not_started_rejects_bids(): void
    {
        $auction = $this->makeAuction(['starts_at' => now()->addHour()]);

        $this->expectException(BidRejected::class);
        app(BidPlacer::class)->place($auction, $this->buyer(), 220);
    }

    public function test_the_seller_cannot_bid_through_the_service_either(): void
    {
        $auction = $this->makeAuction();

        $this->expectException(BidRejected::class);
        app(BidPlacer::class)->place($auction, $auction->seller, 220);
    }

    public function test_amounts_with_sub_cent_precision_are_rejected(): void
    {
        $auction = $this->makeAuction();

        $this->expectException(BidRejected::class);
        app(BidPlacer::class)->place($auction, $this->buyer(), 220.001);
    }

    public function test_events_and_notifications_fire_only_after_the_transaction_commits(): void
    {
        Notification::fake();
        $auction = $this->makeAuction();
        [$first, $second] = [$this->buyer(), $this->buyer()];
        $placer = app(BidPlacer::class);
        $placer->place($auction, $first, 220);

        $baseline = DB::transactionLevel();
        $levels = [];
        Event::listen(BidPlaced::class, function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $placer->place($auction, $second, 240);

        $this->assertSame([$baseline], $levels, 'BidPlaced must be dispatched once, outside the bid transaction.');
        Notification::assertSentTo($first, OutbidNotification::class);
        Notification::assertNotSentTo($second, OutbidNotification::class);
    }

    public function test_a_rejected_bid_dispatches_nothing_and_writes_nothing(): void
    {
        Event::fake([BidPlaced::class]);
        Notification::fake();
        $auction = $this->makeAuction();

        try {
            app(BidPlacer::class)->place($auction, $this->buyer(), 205);
        } catch (BidRejected) {
        }

        Event::assertNotDispatched(BidPlaced::class);
        Notification::assertNothingSent();
        $this->assertSame(0, Bid::count());
        $this->assertEquals(200, $auction->fresh()->current_price);
    }

    public function test_the_same_submission_token_cannot_be_used_twice(): void
    {
        $auction = $this->makeAuction();
        $buyer = $this->buyer();
        $placer = app(BidPlacer::class);

        $placer->place($auction, $buyer, 220, 'token-1');

        try {
            // Different (otherwise legal) amount, so only the token can be the reason.
            $placer->place($auction, $buyer, 300, 'token-1');
            $this->fail('A replayed token must be rejected.');
        } catch (BidRejected $e) {
            $this->assertStringContainsString('already submitted', $e->getMessage());
        }

        $this->assertSame(1, Bid::count());
    }

    public function test_a_rejected_attempt_does_not_burn_the_token(): void
    {
        $auction = $this->makeAuction();
        $buyer = $this->buyer();
        $placer = app(BidPlacer::class);

        try {
            $placer->place($auction, $buyer, 205, 'token-2'); // below minimum
        } catch (BidRejected) {
        }

        $placer->place($auction, $buyer, 220, 'token-2');
        $this->assertSame(1, Bid::count());
    }

    public function test_an_identical_amount_from_the_same_bidder_is_rejected_as_a_duplicate(): void
    {
        $auction = $this->makeAuction();
        $buyer = $this->buyer();
        $placer = app(BidPlacer::class);
        $placer->place($auction, $buyer, 220);

        try {
            $placer->place($auction, $buyer, 220);
            $this->fail('Duplicate amount must be rejected.');
        } catch (BidRejected $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getMessage()));
        }

        $this->assertSame(1, Bid::count());
    }

    public function test_bidding_is_rate_limited_per_user(): void
    {
        $auction = $this->makeAuction(['bid_increment' => 1]);
        $buyer = $this->buyer();

        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('bid:'.$buyer->id, 60);
        }

        Livewire::actingAs($buyer)
            ->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 201)
            ->call('placeBid')
            ->assertSet('success', null)
            ->assertSet('error', fn ($error) => str_contains((string) $error, 'Too many bids'));

        $this->assertSame(0, Bid::count());
    }

    public function test_the_panel_prefills_and_rotates_a_submission_token_after_success(): void
    {
        $auction = $this->makeAuction();
        $buyer = $this->buyer();

        $panel = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction]);
        $first = $panel->get('submissionToken');
        $this->assertNotEmpty($first);

        $panel->set('bidAmount', 220)->call('placeBid')->assertSet('success', 'Bid placed successfully!');

        $this->assertNotSame($first, $panel->get('submissionToken'));
    }

    public function test_the_database_refuses_a_second_winning_bid_for_one_auction(): void
    {
        $auction = $this->makeAuction();
        [$a, $b] = [$this->buyer(), $this->buyer()];

        Bid::create(['auction_id' => $auction->id, 'bidder_id' => $a->id, 'amount' => 220, 'is_winning' => true]);

        $this->expectException(QueryException::class);
        Bid::create(['auction_id' => $auction->id, 'bidder_id' => $b->id, 'amount' => 240, 'is_winning' => true]);
    }

    public function test_many_non_winning_bids_per_auction_are_still_allowed(): void
    {
        $auction = $this->makeAuction();
        $a = $this->buyer();

        foreach ([220, 240, 260] as $amount) {
            Bid::create(['auction_id' => $auction->id, 'bidder_id' => $a->id, 'amount' => $amount, 'is_winning' => false]);
        }

        $this->assertSame(3, Bid::count());
    }

    public function test_the_migration_repairs_legacy_duplicate_winners_before_building_the_index(): void
    {
        $auction = $this->makeAuction();
        [$a, $b] = [$this->buyer(), $this->buyer()];
        $migration = require database_path('migrations/2026_10_18_000001_add_bid_integrity_indexes.php');

        $migration->down(); // back to the pre-fix schema, where double winners were possible
        $now = now();
        foreach ([[$a, '220.00'], [$b, '240.00']] as [$user, $amount]) {
            DB::table('bids')->insert([
                'auction_id' => $auction->id, 'bidder_id' => $user->id, 'amount' => $amount,
                'is_winning' => true, 'is_auto_bid' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $migration->up();

        $this->assertSame(1, $this->winnerCount($auction));
        $this->assertSame($b->id, $auction->winningBid()->first()->bidder_id);
        $this->assertSame(2, Bid::count(), 'Ledger rows are demoted, never deleted.');
    }

    public function test_lookup_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('bids', ['auction_id', 'amount']));
        $this->assertTrue(Schema::hasIndex('auctions', ['status', 'ends_at']));
    }

    public function test_money_helpers_use_integer_cents(): void
    {
        $auction = $this->makeAuction(['current_price' => '0.10', 'bid_increment' => '0.20']);

        $this->assertSame(30, $auction->minimumBidCents());
        $this->assertSame(10, Auction::toCents('0.10'));
        $this->assertSame(1999, Auction::toCents(19.99));
        $this->assertSame(1999, Auction::toCents('19.99'));
        $this->assertSame('R19.99', Auction::money('19.99'));
        $this->assertSame('R1,234.50', Auction::money(1234.5));
    }

    public function test_reserve_met_is_exact_at_the_boundary(): void
    {
        $auction = $this->makeAuction(['reserve_price' => '0.30', 'current_price' => '0.30']);

        $this->assertTrue($auction->reserveMet());
        $this->assertFalse($this->makeAuction(['reserve_price' => '0.30', 'current_price' => '0.29'])->reserveMet());
    }
}
