<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\User;
use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * auction:publish-metrics end to end: one queued, signed job per company, the same snapshot
 * identity on every run of a day, and no row-level data anywhere in what leaves the app.
 */
class PublishMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-auction-tests-0001';

    private const COMPANY_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const COMPANY_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const CONTRACT = ['active', 'ended_7d', 'gmv_7d_cents', 'bids_7d', 'currency'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        Cache::flush();
        config([
            'analytics' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'auction', 'timeout' => 5,
                'circuit' => ['threshold' => 5, 'pause_seconds' => 60]],
            'auction.currency_code' => 'ZAR',
        ]);
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['data' => ['replayed' => false]], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seller(?string $uuid, string $name = 'Seller', ?string $email = null): User
    {
        $user = User::factory()->withPersonalTeam()->create(array_filter(['name' => $name, 'email' => $email]));

        if ($uuid !== null) {
            $user->currentTeam->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        return $user->fresh();
    }

    private function auction(User $seller, array $extra = []): Auction
    {
        return Auction::factory()->create($extra + ['seller_id' => $seller->id]);
    }

    /** @return list<array{request: Request, json: array<string, mixed>}> */
    private function sent(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            $out[] = ['request' => $request, 'json' => json_decode($request->body(), true)];
        }

        return $out;
    }

    private function twoCompanies(): array
    {
        $a = $this->seller(self::COMPANY_A, 'Seller A');
        $b = $this->seller(self::COMPANY_B, 'Seller B');
        $this->auction($a);
        $this->auction($b);
        $this->auction($b);

        return [$a, $b];
    }

    // ---- one job per company ---------------------------------------------------------------

    public function test_one_job_is_queued_per_company_with_the_final_payload(): void
    {
        Bus::fake();
        $this->twoCompanies();
        $this->seller(null);   // an unlinked seller is nobody's company
        $this->auction(User::query()->latest('id')->first());

        $this->artisan('auction:publish-metrics')->assertSuccessful()->expectsOutputToContain('Queued 2 of 2 snapshot(s) for 2026-11-05T00:00:00Z.');

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 2);
        $payloads = [];
        Bus::assertDispatched(SendAnalyticsSnapshot::class, function (SendAnalyticsSnapshot $job) use (&$payloads) {
            $payloads[$job->payload['company_uuid']] = $job->payload;

            return true;
        });
        ksort($payloads);
        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($payloads));
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($payloads[self::COMPANY_A]));
        $this->assertSame(1, $payloads[self::COMPANY_A]['metrics']['active']);
        $this->assertSame(2, $payloads[self::COMPANY_B]['metrics']['active']);
        Http::assertNothingSent();
    }

    public function test_with_the_queue_running_inline_each_company_gets_one_signed_request_with_only_contract_keys(): void
    {
        $this->twoCompanies();

        $this->artisan('auction:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $byCompany = [];
        foreach ($sent as ['request' => $request, 'json' => $json]) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET), $request->header('X-InfoDot-Signature')[0]);
            $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
            $this->assertSame('auction', $json['platform']);
            $this->assertSame('2026-11-05T00:00:00Z', $json['taken_at']);
            $this->assertSame('day', $json['period']);
            $this->assertSame([], array_diff(array_keys($json['metrics']), self::CONTRACT), 'only contract keys');
            $this->assertSame('ZAR', $json['metrics']['currency']);
            $byCompany[$json['company_uuid']] = $json['metrics'];
        }
        $this->assertSame(1, $byCompany[self::COMPANY_A]['active']);
        $this->assertSame(2, $byCompany[self::COMPANY_B]['active']);
    }

    // ---- replay safety ---------------------------------------------------------------------

    public function test_running_twice_on_the_same_day_sends_the_same_snapshot_identity_even_hours_apart(): void
    {
        $this->twoCompanies();

        $this->artisan('auction:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-05 17:42:09');
        $this->artisan('auction:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(4, $sent);
        $identities = array_map(fn ($e) => $e['json']['platform'].'|'.$e['json']['company_uuid'].'|'.$e['json']['taken_at'].'|'.$e['json']['period'], $sent);
        $this->assertCount(2, array_unique($identities), 'two companies, one identity each, however often it runs that day');
        foreach ($sent as $e) {
            $this->assertSame('2026-11-05T00:00:00Z', $e['json']['taken_at']);
        }
    }

    public function test_the_next_utc_day_is_a_new_snapshot_and_a_late_night_run_belongs_to_its_own_utc_day(): void
    {
        $this->twoCompanies();

        Carbon::setTestNow('2026-11-05 23:59:59');
        $this->artisan('auction:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-06 00:00:00');
        $this->artisan('auction:publish-metrics')->assertSuccessful();

        $days = array_unique(array_map(fn ($e) => $e['json']['taken_at'], $this->sent()));
        sort($days);
        $this->assertSame(['2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'], $days);
    }

    public function test_a_late_retry_resends_the_payload_it_was_queued_with_not_fresh_numbers(): void
    {
        Bus::fake();
        [$a] = $this->twoCompanies();
        $this->artisan('auction:publish-metrics')->assertSuccessful();
        $job = Bus::dispatched(SendAnalyticsSnapshot::class)->first(fn ($j) => $j->payload['company_uuid'] === self::COMPANY_A);

        $this->auction($a);                           // the world moves on...
        $this->auction($a);
        Carbon::setTestNow('2026-11-05 09:00:00');    // ...and the retry happens hours later
        $job->handle(app(AnalyticsClient::class));

        $body = $this->sent()[0]['json'];
        $this->assertSame(1, $body['metrics']['active']);
        $this->assertSame('2026-11-05T00:00:00Z', $body['taken_at']);
    }

    // ---- options ---------------------------------------------------------------------------

    public function test_company_limits_the_run_to_one_company(): void
    {
        $this->twoCompanies();

        $this->artisan('auction:publish-metrics', ['--company' => strtoupper(self::COMPANY_B)])->assertSuccessful();

        $this->assertSame([self::COMPANY_B], array_map(fn ($e) => $e['json']['company_uuid'], $this->sent()));
    }

    public function test_company_with_nothing_to_publish_sends_nothing_and_a_malformed_uuid_is_an_error(): void
    {
        $this->twoCompanies();

        $this->artisan('auction:publish-metrics', ['--company' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])
            ->assertSuccessful()->expectsOutputToContain('No company has anything to publish for that uuid.');
        $this->artisan('auction:publish-metrics', ['--company' => 'not-a-uuid'])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_prints_the_numbers_and_queues_and_sends_nothing(): void
    {
        Bus::fake();
        $this->twoCompanies();

        $this->artisan('auction:publish-metrics', ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain(self::COMPANY_A.' {"active":1,"ended_7d":0,"gmv_7d_cents":0,"bids_7d":0,"currency":"ZAR"}')
            ->expectsOutputToContain('Dry run: 2 snapshot(s) for 2026-11-05T00:00:00Z would be sent. Nothing was queued.');

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_dry_run_works_before_the_secret_is_set_and_still_sends_nothing(): void
    {
        config(['analytics.secret' => null, 'analytics.url' => null]);
        $this->twoCompanies();

        $this->artisan('auction:publish-metrics', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain(self::COMPANY_B);

        Http::assertNothingSent();
    }

    // ---- nothing until configured -----------------------------------------------------------

    public function test_nothing_is_collected_queued_or_sent_while_the_url_or_the_secret_is_blank(): void
    {
        Bus::fake();
        $this->twoCompanies();
        $this->mock(MetricsCollector::class)->shouldNotReceive('snapshots');

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['analytics' => array_merge(config('analytics'), ['url' => self::URL, 'secret' => self::SECRET], $blank)]);

            $this->artisan('auction:publish-metrics')->assertSuccessful()->expectsOutputToContain('publishing is off');
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    // ---- Analytics can never hurt the app ----------------------------------------------------

    public function test_an_analytics_outage_leaves_the_command_the_data_and_the_app_untouched(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('boom', 503)]);
        [$a] = $this->twoCompanies();
        $before = Auction::query()->count();

        $this->artisan('auction:publish-metrics')->assertSuccessful();

        $this->assertSame($before, Auction::query()->count());
        $this->assertSame(1, Auction::query()->where('seller_id', $a->id)->count());
        $this->assertGreaterThanOrEqual(2, count($this->sent()));
    }

    public function test_a_connection_failure_or_a_dead_queue_does_not_fail_the_command(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->twoCompanies();
        $this->artisan('auction:publish-metrics')->assertSuccessful();

        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down'));
        });
        $this->artisan('auction:publish-metrics')->assertSuccessful()->expectsOutputToContain('Queued 0 of 2');
    }

    public function test_a_collector_bug_is_logged_and_fails_only_this_command_with_nothing_sent(): void
    {
        $this->mock(MetricsCollector::class)->shouldReceive('snapshots')->andThrow(new \RuntimeException('bad query'));

        $this->artisan('auction:publish-metrics')->assertFailed()->expectsOutputToContain('Nothing was sent.');

        Http::assertNothingSent();
    }

    // ---- aggregates only ----------------------------------------------------------------------

    public function test_no_row_level_data_is_in_the_raw_body(): void
    {
        $sentinels = [
            'name' => 'SENTINEL-SELLER-NAME-'.str_repeat('n', 60),
            'email' => 'sentinel-seller-email-'.str_repeat('e', 40).'@example.test',
            'title' => 'SENTINEL-AUCTION-TITLE-'.str_repeat('t', 60),
            'description' => 'SENTINEL-AUCTION-DESCRIPTION-'.str_repeat('d', 60),
            'bidder' => 'SENTINEL-BIDDER-NAME-'.str_repeat('b', 60),
            'bidder_email' => 'sentinel-bidder-email-'.str_repeat('m', 40).'@example.test',
        ];
        $seller = $this->seller(self::COMPANY_A, $sentinels['name'], $sentinels['email']);
        $seller->currentTeam->forceFill(['name' => 'SENTINEL-TEAM-NAME-'.str_repeat('x', 60)])->save();
        $bidder = User::factory()->withPersonalTeam()->create(['name' => $sentinels['bidder'], 'email' => $sentinels['bidder_email']]);
        $auction = $this->auction($seller, ['title' => $sentinels['title'], 'description' => $sentinels['description'], 'reserve_price' => '777.77']);
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $bidder->id, 'amount' => '222.22', 'is_winning' => true]);
        $bid->forceFill(['created_at' => Carbon::parse('2026-11-02 10:00:00')])->save();
        AuctionSettlement::create(['auction_id' => $auction->id, 'winning_bid_id' => $bid->id, 'seller_id' => $seller->id, 'winner_id' => $bidder->id,
            'amount_cents' => 22222, 'currency' => 'ZAR', 'status' => 'awaiting_payment'])->forceFill(['created_at' => Carbon::parse('2026-11-02 10:05:00')])->save();

        $this->artisan('auction:publish-metrics')->assertSuccessful();

        $body = $this->sent()[0]['request']->body();
        $json = json_decode($body, true);
        $this->assertSame(1, $json['metrics']['bids_7d']);
        $this->assertSame(22222, $json['metrics']['gmv_7d_cents']);
        foreach ($sentinels as $label => $sentinel) {
            $this->assertStringNotContainsString($sentinel, $body, "{$label} leaked");
        }
        foreach (['SENTINEL', 'example.test', '777.77', 'reserve', 'title', 'email', 'name', (string) $seller->id.'@'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$needle} leaked");
        }
        $this->assertStringNotContainsString(self::COMPANY_B, $body);
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
        foreach ($json['metrics'] as $key => $value) {
            $this->assertTrue(is_int($value) || $key === 'currency', "{$key} must be a whole number");
        }
    }

    // ---- schedule ----------------------------------------------------------------------------

    public function test_it_is_scheduled_once_a_day_just_after_midnight_utc(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'auction:publish-metrics'));

        $this->assertNotNull($event, 'auction:publish-metrics is on the schedule');
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_stays_on_utc_even_when_the_app_timezone_is_not(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']);
        $this->app->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'auction:publish-metrics'));

        Carbon::setTestNow(Carbon::parse('2026-11-05 00:15:00', 'UTC'));
        $dueAtUtcMidnight = $event->isDue($this->app);
        Carbon::setTestNow(Carbon::parse('2026-11-04 22:15:00', 'UTC')); // 00:15 in Johannesburg
        $dueAtLocalMidnight = $event->isDue($this->app);

        $this->assertTrue($dueAtUtcMidnight);
        $this->assertFalse($dueAtLocalMidnight);
    }
}
