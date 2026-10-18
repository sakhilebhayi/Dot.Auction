<?php

namespace Tests\Feature\Analytics;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\CompanyDirectory;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What Dot.Auction measures for each company, and, above all, what it must never include: another
 * company's rows, unlinked sellers, drafts, anything outside the UTC window.
 *
 * "Now" is 2026-11-05 00:15 UTC, so taken_at is 2026-11-05T00:00Z and the 7 day window is
 * [2026-10-29 00:00Z, 2026-11-05 00:00Z).
 */
class MetricsCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const COMPANY_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** The only metric keys Dot.Analytics' config/metrics.php accepts for `auction` (PR #15), plus `currency`. */
    private const CONTRACT = ['active', 'ended_7d', 'gmv_7d_cents', 'bids_7d', 'currency'];

    private Carbon $takenAt;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        $this->takenAt = Carbon::parse('2026-11-05 00:00:00', 'UTC');
        config(['auction.currency_code' => 'ZAR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seller(?string $uuid, string $name = 'Seller'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);

        if ($uuid !== null) {
            $user->currentTeam->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        return $user->fresh();
    }

    /** Another person whose current team is $of's current team: the same company. */
    private function colleague(User $of, string $name = 'Colleague'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $user->forceFill(['current_team_id' => $of->current_team_id])->save();

        return $user->fresh();
    }

    private function bidder(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /** A time given in UTC, as the wall-clock value the database holds in the app timezone. */
    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC')->timezone(config('app.timezone'));
    }

    private function auction(User $seller, array $extra = []): Auction
    {
        return Auction::factory()->create($extra + ['seller_id' => $seller->id]);
    }

    private function endedAuction(User $seller, string $endsUtc, string $status = 'ended'): Auction
    {
        return $this->auction($seller, ['status' => $status, 'starts_at' => $this->at('2026-10-01 00:00:00'), 'ends_at' => $this->at($endsUtc)]);
    }

    private function bid(Auction $auction, string $createdUtc): Bid
    {
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $this->bidder()->id, 'amount' => '250.00', 'is_winning' => false]);
        $bid->forceFill(['created_at' => $this->at($createdUtc), 'updated_at' => $this->at($createdUtc)])->save();

        return $bid;
    }

    private function settlement(Auction $auction, int $cents, string $createdUtc, string $currency = 'ZAR'): AuctionSettlement
    {
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $this->bidder()->id, 'amount' => '1.00', 'is_winning' => true]);
        $settlement = AuctionSettlement::create([
            'auction_id' => $auction->id, 'winning_bid_id' => $bid->id, 'seller_id' => $auction->seller_id,
            'winner_id' => $bid->bidder_id, 'amount_cents' => $cents, 'currency' => $currency, 'status' => 'awaiting_payment',
        ]);
        $settlement->forceFill(['created_at' => $this->at($createdUtc)])->save();

        return $settlement;
    }

    /** @return array<string, int|string> */
    private function collect(User ...$sellers): array
    {
        return app(MetricsCollector::class)->collect(array_map(fn (User $u) => $u->id, $sellers), $this->takenAt);
    }

    // ---- the numbers ----------------------------------------------------------------------

    public function test_a_company_with_nothing_in_the_window_reports_true_zeros_and_its_currency(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->auction($seller, ['status' => 'draft']);

        $this->assertSame(['active' => 0, 'ended_7d' => 0, 'gmv_7d_cents' => 0, 'bids_7d' => 0, 'currency' => 'ZAR'], $this->collect($seller));
    }

    public function test_active_is_published_open_auctions_inside_their_window_right_now(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->auction($seller);
        $this->auction($seller, ['starts_at' => $this->at('2026-11-05 00:15:00')]);        // opens this very second: open
        $this->auction($seller, ['ends_at' => $this->at('2026-11-05 00:15:00')]);          // closes this very second: still open
        $this->auction($seller, ['ends_at' => $this->at('2026-11-05 00:14:59')]);          // past its close, settlement cron has not run yet
        $this->auction($seller, ['starts_at' => $this->at('2026-11-05 00:15:01'), 'ends_at' => $this->at('2026-11-08 00:00:00')]); // scheduled
        $this->auction($seller, ['status' => 'draft']);
        $this->auction($seller, ['status' => 'cancelled']);
        $this->auction($seller, ['status' => 'ended']);
        $this->auction($seller, ['status' => 'pending_review']);

        $this->assertSame(3, $this->collect($seller)['active']);
    }

    public function test_ended_counts_ended_auctions_whose_close_falls_in_the_utc_window_and_nothing_else(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->endedAuction($seller, '2026-10-29 00:00:00');                       // exactly the window start: in
        $this->endedAuction($seller, '2026-11-04 23:59:59');                       // last second of the window: in
        $this->endedAuction($seller, '2026-11-02 12:00:00');                       // in
        $this->endedAuction($seller, '2026-10-28 23:59:59');                       // a second before the window: out
        $this->endedAuction($seller, '2026-11-05 00:00:00');                       // exactly taken_at: belongs to the next day's window
        $this->endedAuction($seller, '2026-11-03 12:00:00', 'cancelled');          // cancelled: not ended
        $this->endedAuction($seller, '2026-11-03 12:00:00', 'pending_review');     // reserve not met, awaiting the seller: not ended yet
        $this->endedAuction($seller, '2026-11-03 12:00:00', 'draft');
        $this->endedAuction($seller, '2026-11-03 12:00:00', 'active');             // closed but not settled yet: not ended

        $this->assertSame(3, $this->collect($seller)['ended_7d']);
    }

    public function test_bids_counts_every_bid_row_on_this_sellers_auctions_inside_the_window(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $auction = $this->auction($seller);
        $other = $this->auction($seller);
        $this->bid($auction, '2026-10-29 00:00:00');   // in (window start)
        $this->bid($auction, '2026-11-04 23:59:59');   // in
        $this->bid($other, '2026-11-01 08:00:00');     // in, another auction of the same seller
        $this->bid($auction, '2026-10-28 23:59:59');   // out
        $this->bid($auction, '2026-11-05 00:00:00');   // out: taken_at itself belongs to the next window
        $this->bid($auction, '2026-11-05 00:10:00');   // out: placed after taken_at

        $this->assertSame(3, $this->collect($seller)['bids_7d']);
    }

    public function test_gmv_is_the_winning_amounts_of_settlements_recorded_in_the_window_in_integer_cents(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->settlement($this->auction($seller), 125050, '2026-10-29 00:00:00');   // in: R1 250.50
        $this->settlement($this->auction($seller), 99999, '2026-11-04 23:59:59');    // in
        $this->settlement($this->auction($seller), 1, '2026-10-28 23:59:59');        // out
        $this->settlement($this->auction($seller), 7777, '2026-11-05 00:00:00');     // out
        $this->settlement($this->auction($seller), 5000000, '2026-11-02 10:00:00', 'USD'); // another currency: never mixed in

        $metrics = $this->collect($seller);

        $this->assertSame(125050 + 99999, $metrics['gmv_7d_cents']);
        $this->assertIsInt($metrics['gmv_7d_cents']);
        $this->assertSame('ZAR', $metrics['currency']);
    }

    public function test_the_settlement_currency_match_ignores_case(): void
    {
        config(['auction.currency_code' => 'zar']);
        $seller = $this->seller(self::COMPANY_A);
        $this->settlement($this->auction($seller), 4200, '2026-11-02 10:00:00', 'zar');

        $metrics = $this->collect($seller);

        $this->assertSame(4200, $metrics['gmv_7d_cents']);
        $this->assertSame('ZAR', $metrics['currency']);
    }

    public function test_money_is_left_out_entirely_when_the_configured_currency_is_not_an_iso_code(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->settlement($this->auction($seller), 4200, '2026-11-02 10:00:00');

        foreach ([null, '', 'RAND', 'R', 'ZA1'] as $bad) {
            config(['auction.currency_code' => $bad]);
            $metrics = $this->collect($seller);

            $this->assertArrayNotHasKey('gmv_7d_cents', $metrics, var_export($bad, true));
            $this->assertArrayNotHasKey('currency', $metrics);
            $this->assertSame(['active', 'ended_7d', 'bids_7d'], array_keys($metrics));
        }
    }

    public function test_only_contract_keys_are_ever_produced_in_the_contract_order(): void
    {
        $seller = $this->seller(self::COMPANY_A);

        $keys = array_keys($this->collect($seller));

        $this->assertSame(self::CONTRACT, $keys);
    }

    public function test_nothing_but_whole_numbers_and_the_currency_code_is_produced(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->settlement($this->auction($seller), 12345, '2026-11-02 10:00:00');
        $this->bid($this->auction($seller), '2026-11-02 10:00:00');

        foreach ($this->collect($seller) as $key => $value) {
            $key === 'currency' ? $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $value) : $this->assertIsInt($value, $key);
        }
    }

    // ---- UTC windows, whatever the app timezone is ----------------------------------------

    public function test_the_window_is_the_utc_window_even_when_the_app_runs_in_another_timezone(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']);
        $seller = $this->seller(self::COMPANY_A);
        $auction = $this->auction($seller);
        // 22:30 UTC on 4 Nov is 00:30 on 5 Nov in Johannesburg: before taken_at in UTC, "after midnight" on the wall clock.
        $this->bid($auction, '2026-11-04 22:30:00');
        $this->bid($auction, '2026-11-04 22:59:59');
        // 00:30 UTC on 5 Nov is 02:30 in Johannesburg: after taken_at.
        $this->bid($auction, '2026-11-05 00:30:00');
        // 23:30 UTC on 28 Oct is 01:30 on 29 Oct in Johannesburg: before the window starts in UTC.
        $this->bid($auction, '2026-10-28 23:30:00');
        // 00:30 UTC on 29 Oct (02:30 local): inside.
        $this->bid($auction, '2026-10-29 00:30:00');

        $this->assertSame(3, $this->collect($seller)['bids_7d']);
    }

    public function test_a_late_utc_evening_run_still_uses_the_start_of_that_utc_day(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $auction = $this->auction($seller);
        $this->bid($auction, '2026-11-04 23:00:00');
        $this->bid($auction, '2026-11-05 00:00:01');

        $now = Carbon::parse('2026-11-05 23:59:00', 'UTC');
        $metrics = app(MetricsCollector::class)->collect([$seller->id], $now->copy()->startOfDay(), $now);

        $this->assertSame(1, $metrics['bids_7d'], 'the same day always gives the same 7 day numbers, whenever it runs');
    }

    // ---- company scoping: never another company's rows -------------------------------------

    public function test_each_company_gets_only_its_own_sellers_rows(): void
    {
        $a = $this->seller(self::COMPANY_A, 'Seller A');
        $b = $this->seller(self::COMPANY_B, 'Seller B');
        $auctionA = $this->auction($a);
        $auctionB = $this->auction($b);
        $this->auction($b);
        $this->auction($b);
        $this->endedAuction($a, '2026-11-02 10:00:00');
        $this->endedAuction($b, '2026-11-02 10:00:00');
        $this->endedAuction($b, '2026-11-03 10:00:00');
        $this->bid($auctionA, '2026-11-02 10:00:00');
        foreach (range(1, 4) as $i) {
            $this->bid($auctionB, '2026-11-02 1'.$i.':00:00');
        }
        $this->settlement($this->auction($a), 1000, '2026-11-02 10:00:00');
        $this->settlement($this->auction($b), 2000000, '2026-11-02 10:00:00');

        $companies = app(CompanyDirectory::class)->group([$a->id, $b->id]);
        $forA = app(MetricsCollector::class)->collect($companies[self::COMPANY_A], $this->takenAt);
        $forB = app(MetricsCollector::class)->collect($companies[self::COMPANY_B], $this->takenAt);

        // A: auctionA and the settled auction are open; one ended; one bid in the window (the settlement's own bid is dated "now", after taken_at).
        $this->assertSame(['active' => 2, 'ended_7d' => 1, 'gmv_7d_cents' => 1000, 'bids_7d' => 1, 'currency' => 'ZAR'], $forA);
        // B: auctionB, two more and the settled auction are open; two ended; four bids.
        $this->assertSame(['active' => 4, 'ended_7d' => 2, 'gmv_7d_cents' => 2000000, 'bids_7d' => 4, 'currency' => 'ZAR'], $forB);
    }

    public function test_a_sellers_figures_do_not_change_when_another_company_sells_more(): void
    {
        $a = $this->seller(self::COMPANY_A);
        $b = $this->seller(self::COMPANY_B);
        $this->bid($this->auction($a), '2026-11-02 10:00:00');
        $before = $this->collect($a);

        $this->bid($this->auction($b), '2026-11-02 10:00:00');
        $this->settlement($this->auction($b), 55555, '2026-11-02 10:00:00');
        $this->endedAuction($b, '2026-11-02 10:00:00');

        $this->assertSame($before, $this->collect($a));
    }

    public function test_an_empty_seller_list_is_not_everyone(): void
    {
        $this->bid($this->auction($this->seller(self::COMPANY_A)), '2026-11-02 10:00:00');

        $this->assertSame([], app(MetricsCollector::class)->collect([], $this->takenAt));
    }

    public function test_bids_by_a_buyer_in_company_a_on_company_bs_auction_count_for_b_only(): void
    {
        $a = $this->seller(self::COMPANY_A);
        $b = $this->seller(self::COMPANY_B);
        $auctionB = $this->auction($b);
        Bid::create(['auction_id' => $auctionB->id, 'bidder_id' => $a->id, 'amount' => '300.00', 'is_winning' => true])
            ->forceFill(['created_at' => $this->at('2026-11-02 10:00:00')])->save();

        $this->assertSame(0, $this->collect($a)['bids_7d'], 'bidding in someone else\'s sale is not selling');
        $this->assertSame(1, $this->collect($b)['bids_7d']);
    }

    // ---- who counts as a company -----------------------------------------------------------

    public function test_companies_lists_each_linked_sellers_company_once_and_skips_unlinked_sellers_and_draft_only_sellers(): void
    {
        $a1 = $this->seller(self::COMPANY_A);
        $a2 = $this->colleague($a1);
        $b = $this->seller(self::COMPANY_B);
        $unlinked = $this->seller(null);
        $draftOnly = $this->seller((string) Str::uuid());
        $this->seller((string) Str::uuid()); // links but never listed anything
        foreach ([$a1, $a2, $b, $unlinked] as $seller) {
            $this->auction($seller);
        }
        $this->auction($draftOnly, ['status' => 'draft']);

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($snapshots));
        $this->assertSame(2, $snapshots[self::COMPANY_A]['active'], 'both of the company\'s sellers');
        $this->assertSame(1, $snapshots[self::COMPANY_B]['active']);
    }

    public function test_snapshots_can_be_limited_to_one_company(): void
    {
        $this->auction($this->seller(self::COMPANY_A));
        $this->auction($this->seller(self::COMPANY_B));

        $this->assertSame([self::COMPANY_B], array_keys(app(MetricsCollector::class)->snapshots($this->takenAt, self::COMPANY_B)));
        $this->assertSame([], app(MetricsCollector::class)->snapshots($this->takenAt, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'));
    }

    public function test_the_company_is_the_current_team_then_the_personal_team_and_nothing_is_switched(): void
    {
        $colleague = $this->seller(self::COMPANY_A);

        // Current team linked: that company, even though the personal team is linked elsewhere.
        $both = User::factory()->withPersonalTeam()->create();
        $both->personalTeam()->forceFill(['infodot_team_uuid' => self::COMPANY_B])->save();
        $both->forceFill(['current_team_id' => $colleague->current_team_id])->save();
        // Current team (a side team) has no uuid: the personal team's uuid is used.
        $fallback = User::factory()->withPersonalTeam()->create();
        $fallback->personalTeam()->forceFill(['infodot_team_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'])->save();
        $side = Team::forceCreate(['user_id' => $fallback->id, 'name' => 'Side project', 'personal_team' => false]);
        $fallback->forceFill(['current_team_id' => $side->id])->save();
        // No current team at all: personal team.
        $none = User::factory()->withPersonalTeam()->create();
        $none->personalTeam()->forceFill(['infodot_team_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'])->save();
        $none->forceFill(['current_team_id' => null])->save();
        // A blank (not null) uuid is no uuid, and so is a user whose current team does not exist any more.
        $blank = $this->seller('');
        $dangling = User::factory()->withPersonalTeam()->create();
        $dangling->forceFill(['current_team_id' => 999999])->save();

        $groups = app(CompanyDirectory::class)->group([$colleague->id, $both->id, $fallback->id, $none->id, $blank->id, $dangling->id]);

        $this->assertSame([
            self::COMPANY_A => [$colleague->id, $both->id],
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd' => [$fallback->id],
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' => [$none->id],
        ], $groups);
        $this->assertSame($side->id, $fallback->fresh()->current_team_id, 'resolving a company must not switch the user\'s team');
        $this->assertNull($none->fresh()->current_team_id);
        $this->assertSame(999999, $dangling->fresh()->current_team_id);
    }

    public function test_a_seller_who_moved_to_another_company_is_counted_with_their_current_one_only(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->auction($seller);
        $newTeam = Team::forceCreate(['user_id' => $seller->id, 'name' => 'New company', 'personal_team' => false]);
        $newTeam->forceFill(['infodot_team_uuid' => self::COMPANY_B])->save();
        $seller->forceFill(['current_team_id' => $newTeam->id])->save();

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame([self::COMPANY_B], array_keys($snapshots), 'one company per seller at any time, never both');
    }

    public function test_a_company_with_only_an_old_deleted_auction_history_is_still_reported_for_what_it_has(): void
    {
        $seller = $this->seller(self::COMPANY_A);
        $this->endedAuction($seller, '2026-06-01 10:00:00');

        $snapshots = app(MetricsCollector::class)->snapshots($this->takenAt);

        $this->assertSame(0, $snapshots[self::COMPANY_A]['ended_7d']);
    }
}
