<?php

namespace Tests\Feature\Console;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\User;
use Database\Seeders\AuctionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuctionDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('auction:demo-seed')
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Auction::count());
    }

    public function test_the_seeder_class_itself_also_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);

        (new AuctionDemoSeeder)->run();
    }

    public function test_it_builds_every_status_and_is_idempotent(): void
    {
        Storage::fake('auction-images');

        $this->artisan('auction:demo-seed')->assertSuccessful();

        $counts = fn () => [
            'users' => User::count(),
            'auctions' => Auction::count(),
            'bids' => Bid::count(),
            'settlements' => AuctionSettlement::count(),
            'notifications' => DB::table('notifications')->count(),
            'watchlists' => DB::table('watchlists')->count(),
            'images' => DB::table('auction_images')->count(),
        ];

        $first = $counts();

        $this->assertSame(8, $first['users']);
        $this->assertGreaterThanOrEqual(24, $first['auctions']);
        $this->assertGreaterThan(0, $first['images']);

        foreach (['draft', 'active', 'ended', 'cancelled', 'pending_review'] as $status) {
            $this->assertTrue(Auction::where('status', $status)->exists(), "no demo auction in status {$status}");
        }

        $this->assertTrue(Auction::where('status', 'active')->where('starts_at', '>', now())->exists(), 'no scheduled auction');
        $this->assertTrue(Auction::where('snipe_extensions', '>', 0)->exists(), 'no anti-snipe extended auction');
        $this->assertTrue(Auction::whereNotNull('buy_now_price')->where('status', 'ended')->exists(), 'no auction closed by buy now');

        $this->assertTrue(User::where('is_platform_operator', true)->exists());

        // A second run changes nothing.
        $this->artisan('auction:demo-seed')->expectsOutputToContain('already exists')->assertSuccessful();
        $this->assertSame($first, $counts());

        // --fresh rebuilds to the same shape.
        $this->artisan('auction:demo-seed', ['--fresh' => true])->assertSuccessful();
        $this->assertSame($first, $counts());
    }

    public function test_with_payment_off_only_states_that_can_exist_with_payment_off_are_written(): void
    {
        Storage::fake('auction-images');
        config(['paystack.secret_key' => '']);

        $this->artisan('auction:demo-seed')->assertSuccessful();

        // Every sale is awaiting payment with no deadline: nobody could pay, so nobody could be late, pay, or be cancelled.
        $this->assertSame(AuctionSettlement::count(), AuctionSettlement::where('status', AuctionSettlement::AWAITING_PAYMENT)->whereNull('payment_due_at')->count());
        $this->assertGreaterThan(0, AuctionSettlement::count());
        $this->assertSame(0, DB::table('settlement_payments')->count(), 'no payment attempts or events exist while payment is off');
        $this->assertSame(0, DB::table('settlement_events')->count());

        $types = DB::table('notifications')->pluck('data')->map(fn ($d) => json_decode($d, true)['type'] ?? '')->unique()->all();
        foreach (['settlement_overdue', 'settlement_overdue_seller', 'settlement_cancelled', 'settlement_paid', 'settlement_paid_seller'] as $type) {
            $this->assertNotContains($type, $types, "{$type} notice written while payment is off");
        }

        // No notice tells anyone to pay: the copy says payment is not available.
        $won = DB::table('notifications')->get()->filter(fn ($n) => (json_decode($n->data, true)['type'] ?? '') === 'auction_won');
        $this->assertTrue($won->isNotEmpty());
        $this->assertTrue($won->every(fn ($n) => str_contains(json_decode($n->data, true)['message'], 'nothing is due now')));

        // The run leaves the payment switch as it found it.
        $this->assertSame('', config('paystack.secret_key'));
    }

    public function test_the_payments_option_writes_every_payment_state_with_matching_notices_and_history(): void
    {
        Storage::fake('auction-images');
        config(['paystack.secret_key' => '']);

        $this->artisan('auction:demo-seed', ['--payments' => true])->assertSuccessful();

        // The switch is back off after the run, and the key used was the fake one.
        $this->assertSame('', config('paystack.secret_key'));
        $this->assertStringStartsWith('sk_test_', AuctionDemoSeeder::FAKE_PAYSTACK_KEY);

        foreach ([
            AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAID,
            AuctionSettlement::PAYMENT_OVERDUE, AuctionSettlement::CANCELLED,
        ] as $state) {
            $this->assertTrue(AuctionSettlement::where('status', $state)->exists(), "no settlement {$state}");
        }

        foreach (['initiated', 'failed', 'paid', 'unmatched'] as $status) {
            $this->assertTrue(DB::table('settlement_payments')->where('status', $status)->exists(), "no payment {$status}");
        }

        // Each unpaid sale has the deadline payment gives it; the history and the notices say exactly what the status says.
        $this->assertSame(0, AuctionSettlement::whereNull('payment_due_at')->count());
        $notice = fn (string $type) => DB::table('notifications')->get()->filter(fn ($n) => (json_decode($n->data, true)['type'] ?? '') === $type)->count();
        $this->assertSame(AuctionSettlement::where('status', AuctionSettlement::PAYMENT_OVERDUE)->count() + AuctionSettlement::where('status', AuctionSettlement::CANCELLED)->count(), $notice('settlement_overdue'));
        $this->assertSame($notice('settlement_overdue'), $notice('settlement_overdue_seller'));
        $this->assertSame(AuctionSettlement::where('status', AuctionSettlement::CANCELLED)->count(), $notice('settlement_cancelled'));
        $this->assertSame(AuctionSettlement::where('status', AuctionSettlement::PAID)->count(), $notice('settlement_paid'));
        $this->assertSame(AuctionSettlement::where('status', AuctionSettlement::PAID)->count(), DB::table('settlement_events')->where('type', 'paid')->count());
        $this->assertSame(AuctionSettlement::where('status', AuctionSettlement::PAYMENT_OVERDUE)->count() + AuctionSettlement::where('status', AuctionSettlement::CANCELLED)->count(), DB::table('settlement_events')->where('type', 'overdue')->count());
        // A started checkout and a failed attempt exist only on a sale still inside its deadline.
        $attemptSale = AuctionSettlement::find(DB::table('settlement_payments')->where('status', 'initiated')->value('settlement_id'));
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $attemptSale->status);
        $this->assertTrue($attemptSale->payment_due_at->isFuture());
    }

    public function test_demo_times_are_spread_not_one_moment(): void
    {
        Storage::fake('auction-images');
        $this->artisan('auction:demo-seed')->assertSuccessful();

        $notices = DB::table('notifications')->pluck('created_at');
        $this->assertGreaterThan(25, $notices->unique()->count(), 'notice times collapse onto a few moments');
        $this->assertGreaterThan(5, $notices->map(fn ($t) => substr($t, 0, 10))->unique()->count(), 'notices span fewer than six days');
        $this->assertGreaterThan(8, Auction::pluck('created_at')->map(fn ($t) => substr((string) $t, 0, 13))->unique()->count());
    }

    public function test_demo_photos_carry_no_demo_label(): void
    {
        Storage::fake('auction-images');
        $this->artisan('auction:demo-seed')->assertSuccessful();

        $seeder = (new \ReflectionClass(AuctionDemoSeeder::class))->getFileName();
        $this->assertStringNotContainsString('DEMO IMAGE', (string) file_get_contents($seeder));
        $this->assertStringNotContainsString('imagestring', (string) file_get_contents($seeder), 'a demo photo must not print any text');
    }

    public function test_a_half_finished_run_is_rebuilt_not_trusted(): void
    {
        Storage::fake('auction-images');

        // The first demo account exists but the completion marker does not.
        $stray = User::forceCreate([
            'name' => 'Thandi Mokoena', 'email' => 'thandi.mokoena@'.AuctionDemoSeeder::DOMAIN,
            'email_verified_at' => now(), 'password' => 'x',
        ]);

        $this->artisan('auction:demo-seed')->assertSuccessful();

        $this->assertSame(8, User::count());
        $this->assertTrue(User::where('email', AuctionDemoSeeder::COMPLETION_MARKER)->exists());
        $this->assertNotSame($stray->id, User::where('email', 'thandi.mokoena@'.AuctionDemoSeeder::DOMAIN)->value('id'));
    }

    public function test_dev_login_route_exists_in_testing_and_signs_in(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->get('/dev/login/'.$user->email)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->get('/dev/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_dev_login_does_not_redirect_off_site(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->get('/dev/login/'.$user->id.'?to=//evil.example')->assertRedirect('/dashboard');
        $this->get('/dev/login/'.$user->id.'?to=/auctions')->assertRedirect('/auctions');
    }

    public function test_dev_routes_are_not_registered_in_production(): void
    {
        $this->app['env'] = 'production';

        Route::setRoutes(new RouteCollection);
        require base_path('routes/web.php');
        Route::getRoutes()->refreshNameLookups();

        $this->assertFalse(Route::has('dev.login'));
        $this->assertFalse(Route::has('dev.logout'));
    }
}
