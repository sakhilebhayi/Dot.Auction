<?php

namespace Tests\Feature\Audit;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real concurrency: several PHP processes, each with its own database connection, bid at the same instant.
 * SQLite serialises writers and ignores FOR UPDATE, so the row-lock logic in BidPlacer / BuyNowPurchaser can only be
 * shown on PostgreSQL. Run it with `vendor/bin/phpunit -c phpunit.pgsql.xml`; on SQLite it skips.
 *
 * Deliberately NOT RefreshDatabase: that wraps the test in a transaction the child processes cannot see. The rows
 * created here are committed and removed again in tearDown.
 */
class ParallelBiddingPostgresTest extends TestCase
{
    /** @var array<int, int> */
    private array $userIds = [];

    /** @var array<int, int> */
    private array $auctionIds = [];

    /** @var array<int, int> */
    private array $categoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs PostgreSQL: row locks and several real connections.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }

        if (! Schema::hasTable('auctions')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('auctions')) {
            DB::table('notifications')->whereIn('notifiable_id', $this->userIds)->delete();
            DB::table('auctions')->whereIn('id', $this->auctionIds)->delete();
            DB::table('auction_categories')->whereIn('id', $this->categoryIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    private function seedAuction(int $bidders, array $overrides = []): array
    {
        $seller = User::factory()->create();
        $users = User::factory()->count($bidders)->create();
        $auction = Auction::factory()->create(array_merge([
            'seller_id' => $seller->id,
            'starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '5.00',
            'ends_at' => now()->addHours(3),
        ], $overrides));

        // Committed for real: the base class opened no transaction, so nothing to commit.
        $this->userIds = array_merge($this->userIds, [$seller->id], $users->pluck('id')->all());
        $this->auctionIds[] = $auction->id;
        $this->categoryIds[] = $auction->category_id;

        return [$auction, $users->pluck('id')->all()];
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: string, 3?: string}>  $jobs  [userId, mode, amount, token?]
     * @return array<int, array<string, mixed>>
     */
    private function race(Auction $auction, array $jobs): array
    {
        $startAt = microtime(true) + 3.0;
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
        $processes = [];

        foreach ($jobs as $i => $job) {
            $command = [PHP_BINARY, base_path('tests/Support/parallel-bid.php'), (string) $auction->id, (string) $job[0], $job[1], $job[2], (string) $startAt];
            if (isset($job[3])) {
                $command[] = $job[3];
            }

            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $processes[$i] = [$proc, $pipes];
        }

        $results = [];
        foreach ($processes as $i => [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            $line = collect(explode("\n", trim($out)))->last(fn ($l) => str_starts_with($l, '{'));
            $this->assertNotNull($line, "worker {$i} printed no result: ".$out);
            $results[] = json_decode($line, true);
        }

        $this->assertSame([], array_filter($results, fn ($r) => isset($r['crash'])), 'a worker crashed: '.json_encode($results));

        return $results;
    }

    public function test_many_buyers_bidding_the_same_amount_at_once_produce_exactly_one_winner(): void
    {
        [$auction, $users] = $this->seedAuction(16);

        $results = $this->race($auction, array_map(fn ($id) => [$id, 'bid', '105'], $users));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), 'exactly one identical bid can win');
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
        $this->assertEquals(105, $auction->fresh()->current_price);
    }

    public function test_competing_ascending_bids_leave_one_leader_a_monotone_ledger_and_the_right_outbid_notices(): void
    {
        [$auction, $users] = $this->seedAuction(18);
        $jobs = [];
        foreach ($users as $i => $id) {
            $jobs[] = [$id, 'bid', (string) (105 + ($i % 9) * 5)];
        }

        $this->race($auction, $jobs);

        $bids = Bid::where('auction_id', $auction->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(1, $bids->count());
        $this->assertSame(1, $bids->where('is_winning', true)->count());

        $previous = null;
        $leader = null;
        $expectedOutbid = [];
        foreach ($bids as $bid) {
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual(500, Auction::toCents($bid->amount) - Auction::toCents($previous->amount), 'each accepted bid beats the last by at least one increment');
            }
            if ($leader !== null && $leader !== $bid->bidder_id) {
                $expectedOutbid[] = $leader;
            }
            $leader = $bid->bidder_id;
            $previous = $bid;
        }

        $winner = $bids->firstWhere('is_winning', true);
        $this->assertSame($bids->last()->id, $winner->id, 'the winning row is the last accepted bid');
        $this->assertSame((float) $winner->amount, (float) $auction->fresh()->current_price);

        $notified = DB::table('notifications')->where('type', 'like', '%Outbid%')->whereIn('notifiable_id', $users)->pluck('notifiable_id')->map(fn ($v) => (int) $v)->all();
        sort($notified);
        sort($expectedOutbid);
        $this->assertSame($expectedOutbid, $notified, 'every displaced leader, and only they, got one outbid notice');
    }

    public function test_the_same_person_submitting_the_same_bid_many_times_at_once_is_accepted_once(): void
    {
        [$auction, $users] = $this->seedAuction(1);

        $results = $this->race($auction, array_map(fn ($n) => [$users[0], 'bid', '110', 'token-'.$n], range(1, 8)));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
    }

    public function test_a_late_bid_burst_extends_the_close_once_not_once_per_bid(): void
    {
        [$auction, $users] = $this->seedAuction(12, ['ends_at' => now()->addMinutes(3)]);
        $originalEnd = $auction->ends_at->copy();

        $this->race($auction, array_map(fn ($id, $i) => [$id, 'bid', (string) (105 + $i * 5)], $users, array_keys($users)));

        $fresh = $auction->fresh();
        // First accepted bid is inside the 5 minute window and moves the close by 5 minutes; after that the close is
        // 8 minutes away, outside the window, so no further bid extends it.
        $this->assertSame(1, $fresh->snipe_extensions);
        $this->assertEquals($originalEnd->copy()->addMinutes(5)->timestamp, $fresh->ends_at->timestamp);
    }

    public function test_buy_now_racing_bids_ends_with_one_winner_and_a_consistent_price(): void
    {
        [$auction, $users] = $this->seedAuction(12, ['buy_now_price' => '300.00']);
        $jobs = [];
        foreach ($users as $i => $id) {
            $jobs[] = $i % 4 === 0 ? [$id, 'buy', '0'] : [$id, 'bid', (string) (110 + ($i % 10) * 20)];
        }

        $this->race($auction, $jobs);

        $fresh = $auction->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
        $winner = Bid::where('auction_id', $auction->id)->where('is_winning', true)->first();
        $this->assertSame((float) $winner->amount, (float) $fresh->current_price);
        $this->assertSame(300.0, (float) $fresh->current_price, 'the purchase, not a bid, closed it at the Buy Now price');
    }
}
