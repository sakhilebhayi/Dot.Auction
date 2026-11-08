<?php

namespace Tests\Feature\Console;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\User;
use Database\Seeders\AuctionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\RouteCollection;
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

        foreach ([
            AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAID,
            AuctionSettlement::PAYMENT_OVERDUE, AuctionSettlement::CANCELLED,
        ] as $state) {
            $this->assertTrue(AuctionSettlement::where('status', $state)->exists(), "no settlement {$state}");
        }

        $this->assertTrue(User::where('is_platform_operator', true)->exists());

        // A second run changes nothing.
        $this->artisan('auction:demo-seed')->expectsOutputToContain('already exists')->assertSuccessful();
        $this->assertSame($first, $counts());

        // --fresh rebuilds to the same shape.
        $this->artisan('auction:demo-seed', ['--fresh' => true])->assertSuccessful();
        $this->assertSame($first, $counts());
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
