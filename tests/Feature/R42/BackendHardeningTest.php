<?php

namespace Tests\Feature\R42;

use App\Http\Middleware\TrustedHostList;
use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Notifications\OutbidNotification;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * R-42 backend fixes: F-19 trusted hosts, F-24 drafts, F-25 currency symbol, F-26 polling health, F-27 Buy Now double
 * submit, F-28 fillable, F-29 bid text feedback.
 */
class BackendHardeningTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    // ---- F-28

    public function test_the_money_models_do_not_let_a_request_set_a_price_or_a_status(): void
    {
        $seller = User::factory()->create();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);

        $auction->fill(['status' => 'ended', 'current_price' => '1.00', 'seller_id' => 999, 'snipe_extensions' => 5, 'title' => 'Renamed']);

        $this->assertSame('Renamed', $auction->title);
        $this->assertSame('active', $auction->status);
        $this->assertSame('200.00', $auction->current_price);
        $this->assertSame($seller->id, $auction->seller_id);
        $this->assertSame(0, (int) $auction->snipe_extensions);

        foreach ([
            fn () => (new Bid)->fill(['amount' => '1.00', 'is_winning' => true]),
            fn () => (new AuctionSettlement)->fill(['status' => 'paid']),
            fn () => (new SettlementPayment)->fill(['status' => 'paid']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A money model accepted mass assignment.');
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- F-24

    public function test_category_counts_leave_out_drafts(): void
    {
        $category = AuctionCategory::factory()->create(['name' => 'Cameras']);
        Auction::factory()->count(2)->create(['category_id' => $category->id]);
        Auction::factory()->draft()->count(3)->create(['category_id' => $category->id]);

        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->get(route('auctions.index'))
            ->assertOk()
            ->assertSee('Cameras (2)', false);
    }

    public function test_another_sellers_draft_looks_exactly_like_an_id_that_does_not_exist(): void
    {
        $draft = Auction::factory()->draft()->create();
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->actingAs($viewer)->get(route('auctions.show', $draft))->assertNotFound();
        $this->actingAs($viewer)->get(route('auctions.show', 99999999))->assertNotFound();
        $this->actingAs($draft->seller)->get(route('auctions.show', $draft))->assertOk();
    }

    // ---- F-25

    public function test_the_outbid_notice_uses_the_configured_currency_symbol(): void
    {
        config(['auction.currency_symbol' => '$']);
        $auction = Auction::factory()->create();
        $bid = app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');

        $message = (new OutbidNotification($bid))->toDatabase(User::factory()->create())['message'];

        $this->assertStringContainsString('$220.00', $message);
        $this->assertStringNotContainsString('R220', $message);
    }

    public function test_no_php_or_view_hard_codes_the_rand_symbol(): void
    {
        $offenders = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match('/[\'"]R[\'"]\s*\.|[\'"]R\'?\.number_format|\bR\{\{|>R\{|new price R"/', $line)) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // ---- F-26

    public function test_realtime_health_reports_polling_mode_as_a_200_when_reverb_is_intentionally_off(): void
    {
        config(['auction.realtime.enabled' => false, 'broadcasting.default' => 'log']);

        $this->getJson('/up/realtime')
            ->assertOk()
            ->assertExactJson(['status' => 'degraded', 'mode' => 'polling']);
    }

    public function test_realtime_health_is_still_a_503_when_reverb_is_expected_and_missing(): void
    {
        config(['auction.realtime.enabled' => true, 'broadcasting.default' => 'log']);

        $this->getJson('/up/realtime')->assertStatus(503);
    }

    // ---- F-19

    public function test_the_trusted_host_list_defaults_to_the_app_url_host(): void
    {
        config(['app.url' => 'https://auction.infodot.co.za', 'security.trusted_hosts' => []]);

        $this->assertSame(['^auction\.infodot\.co\.za$'], TrustedHostList::patterns(Request::create('/auctions')));
    }

    public function test_the_trusted_host_list_comes_from_config_when_set(): void
    {
        config(['app.url' => 'https://auction.infodot.co.za', 'security.trusted_hosts' => ['Auction.InfoDot.co.za', 'www.example.test']]);

        $this->assertSame(['^auction\.infodot\.co\.za$', '^www\.example\.test$'], TrustedHostList::patterns(Request::create('/')));
    }

    public function test_a_forged_host_is_refused_and_the_health_probe_still_works(): void
    {
        config(['app.url' => 'https://auction.infodot.co.za', 'security.trusted_hosts' => []]);

        try {
            Request::setTrustedHosts(TrustedHostList::patterns(Request::create('/login')));

            $this->assertSame('auction.infodot.co.za', Request::create('https://auction.infodot.co.za/login')->getHost());

            $this->expectException(SuspiciousOperationException::class);
            Request::create('https://evil.test/login')->getHost();
        } finally {
            Request::setTrustedHosts([]);
        }
    }

    public function test_the_health_probe_and_an_unconfigured_app_url_are_never_locked_out(): void
    {
        config(['app.url' => 'https://auction.infodot.co.za', 'security.trusted_hosts' => []]);
        $this->assertSame([], TrustedHostList::patterns(Request::create('/up')));

        config(['app.url' => 'http://localhost']);
        $this->assertSame([], TrustedHostList::patterns(Request::create('/auctions')));
    }

    // ---- F-27

    public function test_double_clicking_buy_now_keeps_the_success_message(): void
    {
        $auction = Auction::factory()->create(['buy_now_price' => '300.00']);
        $buyer = User::factory()->withPersonalTeam()->create();

        $panel = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction])->call('buyNow');
        $panel->assertSet('purchased', true)->assertSet('error', null);
        $success = $panel->get('success');
        $this->assertStringContainsString('You bought this auction', $success);

        $panel->call('buyNow')->assertSet('error', null)->assertSet('success', $success)->assertSet('purchased', true);

        $this->assertSame(1, Bid::count());
    }

    public function test_buy_now_from_a_second_tab_after_the_first_succeeded_is_a_success_not_an_error(): void
    {
        $auction = Auction::factory()->create(['buy_now_price' => '300.00']);
        $buyer = User::factory()->withPersonalTeam()->create();

        $secondTab = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction]);
        app(BuyNowPurchaser::class)->buy($auction->id, $buyer);

        $secondTab->call('buyNow')->assertSet('error', null)->assertSet('purchased', true);
        $this->assertStringContainsString('You bought this auction', $secondTab->get('success'));
        $this->assertSame(1, Bid::count());
    }

    public function test_buy_now_after_someone_else_bought_it_is_still_an_error(): void
    {
        $auction = Auction::factory()->create(['buy_now_price' => '300.00']);
        $loser = User::factory()->withPersonalTeam()->create();

        $panel = Livewire::actingAs($loser)->test(BidPanel::class, ['auction' => $auction]);
        app(BuyNowPurchaser::class)->buy($auction->id, User::factory()->create());

        $panel->call('buyNow')->assertSet('purchased', false)->assertSet('success', null);
        $this->assertNotNull($panel->get('error'));
    }

    // ---- F-29

    public function test_text_in_the_bid_field_gets_a_plain_message(): void
    {
        $auction = Auction::factory()->create();
        $buyer = User::factory()->withPersonalTeam()->create();

        $panel = Livewire::actingAs($buyer)->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', 'abc')
            ->call('placeBid')
            ->assertHasErrors(['bidAmount' => 'numeric']);

        $this->assertSame('Enter your bid as a number, for example 250 or 250.50.', $panel->get('error'));
        $panel->assertSee('Enter your bid as a number');
        $this->assertSame(0, Bid::count());
    }

    public function test_an_empty_bid_field_asks_for_an_amount(): void
    {
        $auction = Auction::factory()->create();

        $panel = Livewire::actingAs(User::factory()->withPersonalTeam()->create())->test(BidPanel::class, ['auction' => $auction])
            ->set('bidAmount', '')
            ->call('placeBid');

        $this->assertSame('Enter the amount you want to bid.', $panel->get('error'));
    }
}
