<?php

namespace Tests\Feature\Security;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The final stack under real concurrency: every child process drives the actual bid panel (Livewire component, rate
 * limiter, idempotency token, then BidPlacer / BuyNowPurchaser) on PostgreSQL. Audit\ParallelBiddingPostgresTest proves the
 * services; this proves nothing in the component layer (token handling, rate limit, success/error state) can weaken them.
 * On SQLite it skips. Not RefreshDatabase: the child processes need to see committed rows, removed again in tearDown.
 */
class ParallelLivewireBiddingPostgresTest extends TestCase
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
        // The cache is the database, as in production, so the idempotency token is shared between the processes.
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'database']);
        $processes = [];

        foreach ($jobs as $i => $job) {
            $command = [PHP_BINARY, base_path('tests/Support/parallel-livewire-bid.php'), (string) $auction->id, (string) $job[0], $job[1], $job[2], (string) $startAt];
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

    public function test_sixteen_people_pressing_place_bid_with_the_same_amount_produce_one_winner(): void
    {
        [$auction, $users] = $this->seedAuction(16);

        $results = $this->race($auction, array_map(fn ($id) => [$id, 'bid', '105'], $users));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
        $this->assertEquals(105, $auction->fresh()->current_price);
        $this->assertSame(15, count(array_filter($results, fn ($r) => ! $r['ok'] && $r['error'] !== null)), 'every loser was told why');
    }

    public function test_one_person_with_one_token_in_eight_tabs_is_accepted_once(): void
    {
        [$auction, $users] = $this->seedAuction(1);

        $results = $this->race($auction, array_map(fn () => [$users[0], 'bid', '110', 'one-token-for-all-tabs'], range(1, 8)));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
        $this->assertCount(7, array_filter($results, fn ($r) => str_contains((string) $r['error'], 'already')), 'the other seven were told it was already submitted or duplicated');
    }

    public function test_a_late_burst_through_the_panel_moves_the_close_once(): void
    {
        [$auction, $users] = $this->seedAuction(12, ['ends_at' => now()->addMinutes(3)]);
        $original = $auction->ends_at->copy();

        $this->race($auction, array_map(fn ($id, $i) => [$id, 'bid', (string) (105 + $i * 5)], $users, array_keys($users)));

        $fresh = $auction->fresh();
        $this->assertSame(1, $fresh->snipe_extensions);
        $this->assertEquals($original->copy()->addMinutes(5)->timestamp, $fresh->ends_at->timestamp);
    }

    public function test_buy_now_pressed_while_others_bid_ends_with_one_winner_and_a_consistent_price(): void
    {
        [$auction, $users] = $this->seedAuction(12, ['buy_now_price' => '300.00']);
        $jobs = [];
        foreach ($users as $i => $id) {
            $jobs[] = $i % 4 === 0 ? [$id, 'buy', '0'] : [$id, 'bid', (string) (110 + ($i % 10) * 20)];
        }

        $results = $this->race($auction, $jobs);

        $fresh = $auction->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
        $winner = Bid::where('auction_id', $auction->id)->where('is_winning', true)->first();
        $this->assertSame((float) $winner->amount, (float) $fresh->current_price);
        $this->assertSame(300.0, (float) $fresh->current_price);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['mode'] === 'buy' && $r['ok']), 'exactly one Buy Now succeeded: '.json_encode($results));
    }

    public function test_the_ledger_after_a_mixed_race_is_strictly_increasing_and_untouched_by_the_losers(): void
    {
        [$auction, $users] = $this->seedAuction(20);
        $jobs = [];
        foreach ($users as $i => $id) {
            $jobs[] = [$id, 'bid', (string) (105 + ($i % 7) * 5)];
        }

        $this->race($auction, $jobs);

        $amounts = Bid::where('auction_id', $auction->id)->orderBy('id')->pluck('amount')->map(fn ($a) => Auction::toCents($a))->all();
        $this->assertNotEmpty($amounts);
        $sorted = $amounts;
        sort($sorted);
        $this->assertSame($sorted, $amounts, 'accepted bids were written in increasing order');
        $this->assertSame(count($amounts), count(array_unique($amounts)), 'no two accepted bids share an amount');
        $this->assertSame(1, Bid::where('auction_id', $auction->id)->where('is_winning', true)->count());
    }
}
