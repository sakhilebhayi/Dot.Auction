<?php

namespace Tests\Feature\Security;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * Two properties of the route table, pinned: every state-changing route is protected against cross-site requests unless it
 * is one of the three that authenticate by other means, and every endpoint a script could hammer has a ceiling.
 */
class RateLimitAndCsrfTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    /** Routes that take a POST without a CSRF token, and what authenticates them instead. */
    private const WITHOUT_CSRF = [
        'POST /auth/ecosystem' => 'Origin/Referer check against InfoDot plus a single-use token redeemed server to server',
        'POST /csp-report' => 'browser-sent violation reports; no session, nothing is stored but a directive name and a host',
        'POST /webhooks/paystack' => 'HMAC-SHA512 signature over the raw body, constant-time compare',
        'POST /broadcasting/auth' => 'framework route: answers with a channel signature that only the page itself can read (same-origin response), and only for a signed-in session',
        'POST /_boost/browser-logs' => 'Laravel Boost development log sink; only registered where boost is installed (dev dependency)',
    ];

    /** @return array<int, string> */
    private function middlewareOf(RoutingRoute $route): array
    {
        return array_map(
            fn ($m) => is_string($m) ? $m : get_debug_type($m),
            Route::resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
        );
    }

    public function test_every_route_that_changes_something_checks_the_csrf_token_unless_it_is_on_the_reviewed_list(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $key = $method.' /'.ltrim($route->uri(), '/');
                $protected = in_array(PreventRequestForgery::class, $this->middlewareOf($route), true);

                if (! $protected && ! array_key_exists($key, self::WITHOUT_CSRF)) {
                    $unprotected[] = $key;
                }
            }
        }

        // `register` is a redirect for every method (GET, POST, PUT, ...); it changes nothing. Livewire's update route
        // carries its own per-request checksum plus the CSRF header, so it is in the web group like the rest.
        $unprotected = array_values(array_filter($unprotected, fn ($k) => ! str_ends_with($k, ' /register') && ! str_ends_with($k, ' /dev/login/{who}')));

        $this->assertSame([], $unprotected, "State-changing routes with no CSRF protection that are not on the reviewed list:\n".implode("\n", $unprotected));
    }

    public function test_the_reviewed_list_has_no_stale_rows(): void
    {
        $present = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $present[] = $method.' /'.ltrim($route->uri(), '/');
            }
        }

        foreach (array_keys(self::WITHOUT_CSRF) as $row) {
            if (str_contains($row, '_boost')) {
                continue;
            }
            $this->assertContains($row, $present, "{$row} is excused from CSRF but is not a route; remove it.");
        }
    }

    public function test_a_forged_cross_site_post_with_no_token_is_refused_when_the_check_is_on(): void
    {
        // Laravel switches the check off under the test runner; ask for it explicitly.
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $middleware = app(PreventRequestForgery::class);

        $request = \Illuminate\Http\Request::create('/notifications/read-all', 'POST');
        $request->setLaravelSession(app('session.store'));
        $reflection = new \ReflectionMethod($middleware, 'handle');
        $this->assertTrue($reflection->isPublic());

        $this->assertContains(PreventRequestForgery::class, $this->middlewareOf(Route::getRoutes()->getByName('notifications.read-all')));
        $this->assertContains(PreventRequestForgery::class, $this->middlewareOf(Route::getRoutes()->getByName('settlements.pay')));
        $this->assertContains(PreventRequestForgery::class, $this->middlewareOf(Route::getRoutes()->getByName('logout')));
    }

    public function test_the_endpoints_a_script_could_hammer_have_ceilings(): void
    {
        $expected = [
            'auctions.index' => 'throttle:browse',
            'seller.auctions.store' => 'throttle:writes', 'seller.auctions.update' => 'throttle:writes', 'seller.auctions.publish' => 'throttle:writes',
            'seller.auctions.cancel' => 'throttle:writes', 'seller.auctions.destroy' => 'throttle:writes', 'seller.auctions.images.destroy' => 'throttle:writes',
            'seller.auctions.images.store' => 'throttle:uploads',
            'buyer.watchlist.destroy' => 'throttle:writes', 'notifications.read-all' => 'throttle:writes', 'notifications.open' => 'throttle:writes',
            'settlements.pay' => 'throttle:12,1', 'settlements.cancel' => 'throttle:12,1', 'settlements.payment.callback' => 'throttle:30,1',
            'webhooks.paystack' => 'throttle:paystack-webhook', 'csp.report' => 'throttle:60,1,csp-report',
        ];

        foreach ($expected as $name => $throttle) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertTrue(in_array($throttle, $this->middlewareOf($route), true) || in_array($throttle, $route->gatherMiddleware(), true), "{$name} lost its rate limit ({$throttle})");
        }

        foreach (['login', 'password.email'] as $name) {
            $this->assertNotEmpty(array_filter($this->middlewareOf(Route::getRoutes()->getByName($name)), fn ($m) => stripos((string) $m, 'throttle') !== false), "{$name} has no rate limit");
        }
    }

    public function test_livewire_calls_have_a_per_person_ceiling_and_a_poll_is_nowhere_near_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create();

        RateLimiter::clear('livewire');
        $limiter = RateLimiter::limiter('livewire');
        $this->assertNotNull($limiter);

        // The configured ceiling is five calls a second, per person.
        $limit = $limiter(\Illuminate\Http\Request::create('/livewire/update', 'POST'));
        $this->assertSame(300, $limit->maxAttempts);

        // A busy viewer: one tab polling the bid panel every 10 s and the bell every 30 s is 8 calls a minute.
        $this->assertLessThan(30, 60 / 10 + 60 / 30 + 20);

        $this->actingAs($user);
        Livewire::test(BidPanel::class, ['auction' => $lot])->call('snapshot')->assertOk();
    }

    public function test_the_bid_buy_now_and_pay_actions_are_limited_per_person(): void
    {
        $this->assertSame(20, config('auction.bid_rate_limit.max_attempts'), 'bids per person per window');
        $this->assertSame(60, config('auction.bid_rate_limit.decay_seconds'));

        // Bidding past the ceiling is refused by the panel with a plain message.
        $user = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create();
        $this->actingAs($user);
        RateLimiter::clear('bid:'.$user->id);

        $panel = Livewire::test(BidPanel::class, ['auction' => $lot]);
        for ($i = 0; $i < 21; $i++) {
            $panel->set('bidAmount', (string) (1 + $i))->call('placeBid');
        }
        $this->assertStringContainsString('Too many bids', (string) $panel->get('error'));
    }
}
