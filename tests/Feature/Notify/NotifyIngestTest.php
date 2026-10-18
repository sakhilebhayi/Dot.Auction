<?php

namespace Tests\Feature\Notify;

use App\Jobs\SendNotifyEvent;
use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\Team;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BuyNowPurchaser;
use App\Services\Notify\NotifyClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Dot.Auction tells Dot.Notify about the events it already raises in-app.
 * Nothing here may ever slow, break or change the user action or the
 * in-app notification, so most of these tests prove what does NOT happen.
 */
class NotifyIngestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'notify-ingest-secret-for-auction-tests-0001';

    private const SELLER_UUID = '11111111-1111-4111-8111-111111111111';

    private const BUYER_UUID = '22222222-2222-4222-8222-222222222222';

    private const RIVAL_UUID = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        Cache::flush();
        config([
            'app.url' => 'https://auction.test',
            'notify' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'auction', 'timeout' => 5,
                'circuit' => ['threshold' => 5, 'pause_seconds' => 60]],
            'auction.currency_code' => 'ZAR',
        ]);
        url()->forceRootUrl('https://auction.test');
        $this->fakeNotify(Http::response(['id' => 1, 'status' => 'routed'], 202));
    }

    /** Replace the fake (a second Http::fake() would sit behind the first). */
    private function fakeNotify(mixed $stub = null): void
    {
        Http::swap(new HttpFactory);
        Http::fake($stub === null ? null : ['*' => $stub]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(?string $uuid = self::BUYER_UUID, string $name = 'Bidder'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $user->currentTeam->forceFill(['infodot_team_uuid' => $uuid ?? (string) Str::uuid()])->save();

        return $user->fresh();
    }

    private function auction(User $seller, array $extra = []): Auction
    {
        return Auction::factory()->create($extra + [
            'seller_id' => $seller->id, 'title' => 'Vintage Fender Strat 1962',
            'starting_price' => '200.00', 'current_price' => '200.00', 'bid_increment' => '20.00',
        ]);
    }

    /** @return list<array{request: Request, json: array<string, mixed>}> */
    private function sent(?string $event = null): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            $json = json_decode($request->body(), true);
            if ($event === null || $json['event'] === $event) {
                $out[] = ['request' => $request, 'json' => $json];
            }
        }

        return $out;
    }

    private function outbidScenario(bool $unique = false): array
    {
        $seller = $this->member($unique ? null : self::SELLER_UUID, 'Seller');
        $first = $this->member($unique ? null : self::BUYER_UUID, 'First Bidder');
        $second = $this->member($unique ? null : self::RIVAL_UUID, 'Second Bidder');
        $auction = $this->auction($seller);
        app(BidPlacer::class)->place($auction, $first, '240.00');
        $bid = app(BidPlacer::class)->place($auction, $second, '300.50');

        return compact('seller', 'first', 'second', 'auction', 'bid');
    }

    // ---- the signed request --------------------------------------------------------------

    public function test_an_outbid_posts_a_signed_event_to_the_displaced_bidder_only(): void
    {
        ['first' => $first, 'second' => $second, 'auction' => $auction, 'bid' => $bid] = $this->outbidScenario();

        $events = $this->sent('auction.outbid');
        $this->assertCount(1, $events);
        $request = $events[0]['request'];
        $json = $events[0]['json'];
        $body = $request->body();
        $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

        $this->assertSame(self::URL, $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), $request->header('X-InfoDot-Signature')[0] ?? '');
        $this->assertLessThanOrEqual(5, abs((int) $timestamp - now()->timestamp));
        $this->assertSame(['platform', 'company_uuid', 'event', 'recipient', 'data', 'idempotency_key'], array_keys($json));
        $this->assertSame('auction', $json['platform']);
        $this->assertSame(self::BUYER_UUID, $json['company_uuid']);
        $this->assertSame(['email' => $first->email], $json['recipient']);
        $this->assertSame(['auction_title', 'your_bid', 'current_price', 'currency', 'ends_at', 'url'], array_keys($json['data']));
        $this->assertSame('Vintage Fender Strat 1962', $json['data']['auction_title']);
        $this->assertSame('240.00', $json['data']['your_bid']);
        $this->assertSame('300.50', $json['data']['current_price']);
        $this->assertSame('ZAR', $json['data']['currency']);
        $this->assertSame($auction->fresh()->ends_at->toIso8601String(), $json['data']['ends_at']);
        $this->assertSame('https://auction.test/auctions/'.$auction->id, $json['data']['url']);
        $this->assertSame('auction.outbid:'.$auction->id.':'.sha1($first->email).':'.$bid->id, $json['idempotency_key']);
        $this->assertStringNotContainsString($second->email, $body, 'the new high bidder is not told to anyone');
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString('First Bidder', $body);
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        $this->fakeNotify(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 202);
        });

        $this->outbidScenario();

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    public function test_buying_now_also_tells_the_displaced_bidder(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $first = $this->member(self::BUYER_UUID);
        $buyer = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($seller, ['buy_now_price' => '900.00']);
        app(BidPlacer::class)->place($auction, $first, '240.00');
        $this->fakeNotify(Http::response([], 202));

        app(BuyNowPurchaser::class)->buy($auction, $buyer);

        $outbid = $this->sent('auction.outbid');
        $this->assertCount(1, $outbid);
        $this->assertSame($first->email, $outbid[0]['json']['recipient']['email']);
        $this->assertSame('900.00', $outbid[0]['json']['data']['current_price']);
    }

    // ---- the other three events ----------------------------------------------------------

    public function test_settling_tells_the_winner_and_the_seller(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $winner = $this->member(self::BUYER_UUID);
        $auction = $this->auction($seller, ['starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10)]);
        $bid = app(BidPlacer::class)->place($auction, $winner, '1234.50');
        $auction->update(['ends_at' => now()->subMinute()]);
        $this->fakeNotify(Http::response([], 202));

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $won = $this->sent('auction.won');
        $this->assertCount(1, $won);
        $this->assertSame(self::BUYER_UUID, $won[0]['json']['company_uuid']);
        $this->assertSame(['email' => $winner->email], $won[0]['json']['recipient']);
        $this->assertSame(['auction_title', 'final_price', 'currency', 'url'], array_keys($won[0]['json']['data']));
        $this->assertSame('1234.50', $won[0]['json']['data']['final_price']);
        $this->assertSame('ZAR', $won[0]['json']['data']['currency']);
        $this->assertSame('https://auction.test/auctions/'.$auction->id, $won[0]['json']['data']['url']);
        $this->assertSame('auction.won:'.$auction->id.':'.sha1($winner->email).':'.$bid->id, $won[0]['json']['idempotency_key']);

        $sold = $this->sent('auction.sold');
        $this->assertCount(1, $sold);
        $this->assertSame(self::SELLER_UUID, $sold[0]['json']['company_uuid']);
        $this->assertSame(['email' => $seller->email], $sold[0]['json']['recipient']);
        $this->assertSame(['auction_title', 'final_price', 'currency', 'status', 'url'], array_keys($sold[0]['json']['data']));
        $this->assertSame('1234.50', $sold[0]['json']['data']['final_price']);
        $this->assertSame('sold', $sold[0]['json']['data']['status']);
        $this->assertStringNotContainsString($winner->email, json_encode($sold[0]['json']), 'the seller is not given the winner address');
        $this->assertStringNotContainsString($seller->email, json_encode($won[0]['json']));
        $this->assertMatchesRegularExpression('/^auction\.sold:'.$auction->id.':'.sha1($seller->email).':[0-9a-f-]{36}$/', $sold[0]['json']['idempotency_key']);
    }

    public function test_running_the_settlement_twice_sends_each_event_once(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $winner = $this->member(self::BUYER_UUID);
        $auction = $this->auction($seller, ['starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10)]);
        app(BidPlacer::class)->place($auction, $winner, '300.00');
        $auction->update(['ends_at' => now()->subMinute()]);
        $this->fakeNotify(Http::response([], 202));

        $this->artisan('auction:settle-ended')->assertSuccessful();
        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertCount(1, $this->sent('auction.won'));
        $this->assertCount(1, $this->sent('auction.sold'));
    }

    public function test_the_seller_accepting_a_reserve_not_met_bid_tells_the_winner(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $bidder = $this->member(self::BUYER_UUID);
        $auction = Auction::create([
            'seller_id' => $seller->id, 'title' => 'Rare Coin', 'starting_price' => 50, 'reserve_price' => 500,
            'current_price' => 300, 'bid_increment' => 10, 'status' => 'pending_review',
            'starts_at' => now()->subDay(), 'ends_at' => now()->subMinute(),
        ]);
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $bidder->id, 'amount' => 300, 'is_winning' => true]);
        $proposal = ReserveNotMetProposal::create(['auction_id' => $auction->id, 'bid_id' => $bid->id, 'reserve_price' => 500, 'status' => 'pending']);

        Livewire::actingAs($seller)->test(ReserveDecisionPanel::class, ['proposal' => $proposal])->call('accept');

        $won = $this->sent('auction.won');
        $this->assertCount(1, $won);
        $this->assertSame('Rare Coin', $won[0]['json']['data']['auction_title']);
        $this->assertSame('300.00', $won[0]['json']['data']['final_price']);
        $this->assertSame('auction.won:'.$auction->id.':'.sha1($bidder->email).':'.$bid->id, $won[0]['json']['idempotency_key']);
    }

    public function test_the_ending_soon_command_tells_each_watcher_once(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $watcher = $this->member(self::BUYER_UUID);
        $auction = $this->auction($seller, ['ends_at' => now()->addMinutes(30), 'current_price' => '260.00']);
        Watchlist::withoutGlobalScopes()->create(['user_id' => $watcher->id, 'auction_id' => $auction->id]);

        $this->artisan('auction:notify-ending-soon')->assertSuccessful();
        $this->artisan('auction:notify-ending-soon')->assertSuccessful();

        $events = $this->sent('auction.ending_soon');
        $this->assertCount(1, $events);
        $json = $events[0]['json'];
        $this->assertSame(self::BUYER_UUID, $json['company_uuid']);
        $this->assertSame(['email' => $watcher->email], $json['recipient']);
        $this->assertSame(['auction_title', 'current_price', 'currency', 'ends_at', 'url'], array_keys($json['data']));
        $this->assertSame('260.00', $json['data']['current_price']);
        $this->assertSame(now()->addMinutes(30)->toIso8601String(), $json['data']['ends_at']);
        $this->assertSame('https://auction.test/auctions/'.$auction->id, $json['data']['url']);
        $this->assertSame('auction.ending_soon:'.$auction->id.':'.sha1($watcher->email).':ending-soon', $json['idempotency_key']);
    }

    // ---- nothing is sent unless it can be routed ------------------------------------------

    public function test_every_url_is_https_on_the_app_host_even_when_app_url_is_http(): void
    {
        config(['app.url' => 'http://auction.test']);
        url()->forceRootUrl('http://auction.test');

        $this->outbidScenario();

        $url = $this->sent('auction.outbid')[0]['json']['data']['url'];
        $this->assertStringStartsWith('https://auction.test/auctions/', $url);
        $this->assertSame('auction.test', parse_url($url, PHP_URL_HOST));
    }

    public function test_it_is_disabled_when_the_url_or_the_secret_is_blank(): void
    {
        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['notify' => array_merge(config('notify'), $blank)]);
            $this->assertFalse(app(NotifyClient::class)->enabled());
            $this->outbidScenario(unique: true);
        }

        Http::assertNothingSent();
        Queue::fake();
        $this->outbidScenario(unique: true);
        Queue::assertNotPushed(SendNotifyEvent::class);
    }

    public function test_a_recipient_without_a_company_uuid_is_skipped(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $first = User::factory()->withPersonalTeam()->create(); // personal team, never linked to InfoDot
        $second = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($seller);
        app(BidPlacer::class)->place($auction, $first, '240.00');

        app(BidPlacer::class)->place($auction, $second, '300.00');

        Http::assertNothingSent();
        $this->assertSame(1, $first->notifications()->count(), 'the in-app notification is untouched');
    }

    public function test_a_user_with_no_team_at_all_is_skipped_without_writing_anything(): void
    {
        $first = User::factory()->create(); // no team
        $second = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($this->member(self::SELLER_UUID, 'Seller'));
        app(BidPlacer::class)->place($auction, $first, '240.00');

        app(BidPlacer::class)->place($auction, $second, '300.00');

        Http::assertNothingSent();
        $this->assertNull($first->fresh()->current_team_id, 'resolving a company must not switch the user team');
    }

    public function test_the_personal_team_uuid_is_used_when_the_current_team_has_none(): void
    {
        $first = $this->member(self::BUYER_UUID);
        $other = Team::forceCreate(['user_id' => $first->id, 'name' => 'Side project', 'personal_team' => false]);
        $first->forceFill(['current_team_id' => $other->id])->save();
        $second = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($this->member(self::SELLER_UUID, 'Seller'));
        app(BidPlacer::class)->place($auction, $first->fresh(), '240.00');

        app(BidPlacer::class)->place($auction, $second, '300.00');

        $this->assertSame(self::BUYER_UUID, $this->sent('auction.outbid')[0]['json']['company_uuid']);
    }

    public function test_a_recipient_without_an_email_is_skipped(): void
    {
        $user = $this->member();
        $user->email = '';
        $auction = $this->auction($this->member(self::SELLER_UUID, 'Seller'));
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $this->member(self::RIVAL_UUID)->id, 'amount' => 300, 'is_winning' => true]);

        $user->notify(new OutbidNotification($bid));

        Http::assertNothingSent();
    }

    // ---- Notify can never hurt the user -------------------------------------------------

    public function test_a_failing_notify_leaves_the_bid_and_the_in_app_notification_untouched(): void
    {
        foreach ([
            fn () => Http::response('boom', 500),
            fn () => Http::response('slow down', 429),
            fn () => Http::response('nope', 401),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            fn () => throw new \RuntimeException('something unexpected'),
        ] as $failure) {
            Cache::flush();
            $calls = 0;
            $this->fakeNotify(function () use ($failure, &$calls) {
                $calls++;

                return $failure();
            });

            ['first' => $first, 'auction' => $auction, 'bid' => $bid] = $this->outbidScenario(unique: true);

            $this->assertTrue($bid->is_winning);
            $this->assertSame('300.50', $auction->fresh()->current_price);
            $this->assertSame(1, $first->notifications()->where('type', OutbidNotification::class)->count());
            $this->assertSame(1, $calls, 'Notify was attempted once and its failure stayed contained');
        }
    }

    public function test_a_failing_notify_does_not_stop_settlement_or_the_seller_notice(): void
    {
        $this->fakeNotify(Http::response('boom', 503));
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $winner = $this->member(self::BUYER_UUID);
        $auction = $this->auction($seller, ['starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10)]);
        app(BidPlacer::class)->place($auction, $winner, '300.00');
        $auction->update(['ends_at' => now()->subMinute()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame(1, $winner->notifications()->count());
        $this->assertSame(1, $seller->notifications()->count());
    }

    public function test_a_failing_notify_does_not_stop_the_ending_soon_command_or_its_marker(): void
    {
        $this->fakeNotify(Http::response('boom', 500));
        $watcher = $this->member(self::BUYER_UUID);
        $auction = $this->auction($this->member(self::SELLER_UUID, 'Seller'), ['ends_at' => now()->addMinutes(30)]);
        Watchlist::withoutGlobalScopes()->create(['user_id' => $watcher->id, 'auction_id' => $auction->id]);

        $this->artisan('auction:notify-ending-soon')->assertSuccessful()->expectsOutputToContain('Sent 1 ending-soon notice(s).');

        $this->assertSame(1, $watcher->notifications()->count());
        $this->assertNotNull(Watchlist::withoutGlobalScopes()->where('user_id', $watcher->id)->value('ending_soon_notified_at'));
    }

    public function test_a_failure_to_even_queue_the_event_never_reaches_the_caller(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $first = $this->member(self::BUYER_UUID);
        $second = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($seller);
        app(BidPlacer::class)->place($auction, $first, '240.00');
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down'));
        });

        $bid = app(BidPlacer::class)->place($auction, $second, '300.00');

        $this->assertTrue($bid->is_winning);
        $this->assertSame(1, $first->notifications()->count());
    }

    public function test_a_transient_failure_asks_the_queue_to_retry_and_a_permanent_one_does_not(): void
    {
        $payload = $this->payload();
        $client = app(NotifyClient::class);
        $this->fakeNotify(Http::sequence()->push('x', 503)->push('x', 429)->push('x', 422)->push([], 200)->pushFailedConnection());

        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload), '422: retrying cannot fix a bad payload');
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload), 'connection failure');
    }

    public function test_the_job_retries_three_times_with_backoff_and_never_throws(): void
    {
        $job = new SendNotifyEvent($this->payload());

        $this->assertSame(3, $job->tries);
        $this->assertTrue($job->afterCommit, 'dispatched only once the surrounding transaction has committed');
        $this->assertCount(2, $job->backoff());
        $this->assertSame($job->backoff(), array_values(array_unique($job->backoff())));

        $this->fakeNotify(fn () => throw new \RuntimeException('unexpected'));
        $job->handle(app(NotifyClient::class));
        $this->assertTrue(true, 'no exception escaped');
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        $payload = $this->payload();

        app(NotifyClient::class)->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(2));
        app(NotifyClient::class)->deliver($payload);

        $stamps = array_map(fn ($e) => $e['request']->header('X-InfoDot-Timestamp')[0], $this->sent());
        $this->assertCount(2, array_unique($stamps));
    }

    public function test_the_idempotency_key_is_stable_across_retries_and_double_dispatch(): void
    {
        ['first' => $first, 'bid' => $bid] = $this->outbidScenario();

        // The same notification built again (a double dispatch) yields the same key.
        $first->notify(new OutbidNotification($bid->fresh()));
        Carbon::setTestNow(now()->addHour());
        $first->notify(new OutbidNotification($bid->fresh()));

        $keys = array_map(fn ($e) => $e['json']['idempotency_key'], $this->sent('auction.outbid'));
        $this->assertCount(3, $keys);
        $this->assertCount(1, array_unique($keys));
    }

    // ---- circuit breaker ------------------------------------------------------------------

    public function test_five_consecutive_failures_pause_calls_for_sixty_seconds_then_it_recovers(): void
    {
        $client = app(NotifyClient::class);
        $payload = $this->payload();
        $this->fakeNotify(Http::response('boom', 500));

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        }
        Http::assertSentCount(5);

        $this->assertSame(NotifyClient::PAUSED, $client->deliver($payload));
        $this->assertSame(NotifyClient::PAUSED, $client->deliver($payload));
        Http::assertSentCount(5);

        Carbon::setTestNow(now()->addSeconds(59));
        $this->assertSame(NotifyClient::PAUSED, $client->deliver($payload));
        Http::assertSentCount(5);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->fakeNotify(Http::response([], 202));
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload), 'the probe after the pause is allowed through');
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        Http::assertSentCount(2);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $client = app(NotifyClient::class);
        $payload = $this->payload();

        $this->fakeNotify(Http::response('boom', 500));
        for ($i = 0; $i < 4; $i++) {
            $client->deliver($payload);
        }
        $this->fakeNotify(Http::response([], 202));
        $client->deliver($payload);
        $this->fakeNotify(Http::response('boom', 500));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(NotifyClient::RETRY, $client->deliver($payload), 'four more failures are still below the threshold');
        }
    }

    public function test_a_refusal_does_not_count_towards_the_breaker(): void
    {
        $client = app(NotifyClient::class);
        $payload = $this->payload();
        $this->fakeNotify(Http::response('no', 422));

        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload));
        }
        Http::assertSentCount(8);
    }

    public function test_a_paused_job_is_released_without_calling_notify(): void
    {
        Cache::put('notify.circuit.open_until', now()->timestamp + 40, 60);
        $delay = null;
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->andReturnUsing(function ($seconds) use (&$delay) {
            $delay = $seconds;
        });
        $job = new SendNotifyEvent($this->payload());
        $job->setJob($queueJob);

        $job->handle(app(NotifyClient::class));

        Http::assertNothingSent();
        $this->assertGreaterThanOrEqual(40, $delay);
    }

    // ---- after commit ---------------------------------------------------------------------

    public function test_nothing_is_queued_inside_a_bid_that_rolls_back(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $first = $this->member(self::BUYER_UUID);
        $second = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($seller);
        app(BidPlacer::class)->place($auction, $first, '240.00');
        Queue::fake();
        $this->fakeNotify();

        try {
            DB::transaction(function () use ($auction, $second) {
                app(BidPlacer::class)->place($auction, $second, '300.00');
                throw new \RuntimeException('something later in the request failed');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame('240.00', $auction->fresh()->current_price);
    }

    public function test_a_notification_raised_inside_a_rolled_back_transaction_queues_nothing(): void
    {
        $first = $this->member(self::BUYER_UUID);
        $auction = $this->auction($this->member(self::SELLER_UUID, 'Seller'));
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $first->id, 'amount' => 240, 'is_winning' => false]);
        $newBid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $this->member(self::RIVAL_UUID)->id, 'amount' => 300, 'is_winning' => true]);
        Queue::fake();
        $this->fakeNotify();

        try {
            DB::transaction(function () use ($first, $newBid) {
                $first->notify(new OutbidNotification($newBid));
                Queue::assertNotPushed(SendNotifyEvent::class);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNotPushed(SendNotifyEvent::class);
        Http::assertNothingSent();
    }

    public function test_the_event_is_queued_once_the_bid_commits_and_not_sent_in_the_request(): void
    {
        $seller = $this->member(self::SELLER_UUID, 'Seller');
        $first = $this->member(self::BUYER_UUID);
        $second = $this->member(self::RIVAL_UUID);
        $auction = $this->auction($seller);
        app(BidPlacer::class)->place($auction, $first, '240.00');
        Queue::fake();
        $this->fakeNotify();

        app(BidPlacer::class)->place($auction, $second, '300.00');

        Queue::assertPushed(SendNotifyEvent::class, 1);
        Queue::assertPushed(SendNotifyEvent::class, fn (SendNotifyEvent $job) => $job->payload['event'] === 'auction.outbid');
        Http::assertNothingSent();
    }

    // ---- privacy of logs ------------------------------------------------------------------

    public function test_failures_are_logged_without_emails_or_secrets(): void
    {
        config([
            'logging.default' => 'notifytest',
            'logging.channels.notifytest' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        ]);
        Log::forgetChannel('notifytest');
        $this->fakeNotify(Http::response('{"error":"a@b.test leaked"}', 500));

        ['first' => $first, 'second' => $second] = $this->outbidScenario();

        $handler = Log::channel('notifytest')->getLogger()->getHandlers()[0];
        $logged = json_encode(array_map(fn ($r) => $r->message.json_encode($r->context), $handler->getRecords()));
        $this->assertNotSame('[]', $logged, 'the failure is logged');
        foreach ([$first->email, $second->email, self::SECRET, 'leaked'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'platform' => 'auction', 'company_uuid' => self::BUYER_UUID, 'event' => 'auction.outbid',
            'recipient' => ['email' => 'someone@example.test'], 'data' => ['auction_title' => 'X'],
            'idempotency_key' => 'auction.outbid:1:abc:1',
        ];
    }
}
