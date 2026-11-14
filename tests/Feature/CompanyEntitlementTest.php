<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * R-50: this platform honours the company's entitlement, at sign-in (the redeem answer) and during a session (the read
 * endpoint), and never lets that check touch a running auction. Response bodies follow InfoDot's own tests
 * (PlatformDoorsTest, CompanyPlatformsEndpointTest).
 */
class CompanyEntitlementTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const READ = 'https://infodot.test/api/ecosystem/companies/*';

    private const NORTHWIND = '11111111-1111-4111-8111-111111111111';

    private const SECOND = '22222222-2222-4222-8222-222222222222';

    private const PERSONAL = '33333333-3333-4333-8333-333333333333';

    private string $bearer;

    /** Calls made to the read endpoint since the last fake was set (a thrown connection error is not in Http::recorded()). */
    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'auction', 'secret' => $this->bearer, 'timeout' => 2, 'provision' => true, 'entitlement_check' => true,
        ]]);
    }

    // ---- helpers ----

    private function team(string $uuid, string $name, string $role = 'owner', ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role, 'infodot_role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'auction',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test']);
    }

    /** The read endpoint's body for one company, with this platform's own row as given. */
    private function platforms(string $uuid, ?array $own): array
    {
        return [
            'platform' => 'auction',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => array_values(array_filter([
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                $own === null ? null : ['key' => 'auction', 'name' => 'Dot.Auction', 'is_core' => false, 'access_mode' => 'everyone'] + $own,
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
            ])),
        ];
    }

    private function infodotAnswers(array|callable $byCompany): void
    {
        Http::swap(new Factory);
        $this->reads = 0;
        Http::fake([self::READ => function (Request $request) use ($byCompany) {
            $this->reads++;

            if (is_callable($byCompany)) {
                return $byCompany($request);
            }

            foreach ($byCompany as $uuid => $own) {
                if (str_contains($request->url(), $uuid)) {
                    return Http::response($this->platforms($uuid, $own));
                }
            }

            return Http::response(['error' => 'not_found'], 404);
        }]);
    }

    /** A person whose current team is a company linked to InfoDot. */
    private function memberOf(string $uuid, string $name = 'Northwind Freight'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function reads(): int
    {
        return $this->reads;
    }

    // ---- sign-in (redeem) ----

    public function test_a_company_marked_entitled_is_admitted_and_becomes_current(): void
    {
        $this->signIn('ann@northwind.test', [$this->team(self::NORTHWIND, 'Northwind Freight', 'owner', true)], self::NORTHWIND)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@northwind.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::NORTHWIND)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
        $this->assertNull(session(EnsureCompanyEntitled::SESSION_KEY), 'the answer said nothing about a personal workspace');
    }

    public function test_a_company_marked_not_entitled_is_not_synced_and_cannot_become_current(): void
    {
        $this->signIn('ann@northwind.test', [
            $this->team(self::NORTHWIND, 'Northwind Freight', 'owner', true),
            $this->team(self::SECOND, 'Second Company', 'owner', false, 'not_activated'),
            $this->team(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
        ], self::SECOND)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@northwind.test')->firstOrFail();
        $this->assertNull(Team::where('infodot_team_uuid', self::SECOND)->first(), 'a company without the platform is not created here');
        $this->assertNotNull(Team::where('infodot_team_uuid', self::NORTHWIND)->first());
        $this->assertNotSame(self::SECOND, $user->currentTeam?->infodot_team_uuid);
    }

    public function test_an_existing_company_that_loses_the_platform_is_left_untouched_and_stops_being_current(): void
    {
        $both = fn (bool $second) => [
            $this->team(self::NORTHWIND, 'Northwind Freight', 'owner', true),
            $this->team(self::SECOND, $second ? 'Second Company' : 'Renamed In InfoDot', 'owner', $second, $second ? null : 'suspended'),
        ];
        $this->signIn('ann@northwind.test', $both(true), self::SECOND);
        $user = User::where('email', 'ann@northwind.test')->firstOrFail();
        $second = Team::where('infodot_team_uuid', self::SECOND)->firstOrFail();
        $this->assertSame($second->id, $user->current_team_id);

        auth()->logout();
        $this->signIn('ann@northwind.test', $both(false), self::SECOND);

        $this->assertSame('Second Company', $second->fresh()->name, 'not updated while it does not hold the platform');
        $this->assertTrue($user->fresh()->belongsToTeam($second->fresh()), 'membership and data stay');
        $this->assertSame(self::NORTHWIND, $user->fresh()->currentTeam->infodot_team_uuid);
    }

    public function test_an_answer_without_the_entitled_key_behaves_exactly_as_before(): void
    {
        $this->signIn('ann@northwind.test', [$this->team(self::NORTHWIND, 'Northwind Freight', 'owner')], self::NORTHWIND)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@northwind.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::NORTHWIND, $user->currentTeam->infodot_team_uuid);
        $this->assertNull(session(EnsureCompanyEntitled::SESSION_KEY));
    }

    public function test_when_no_company_is_entitled_a_plain_page_is_shown_and_nobody_is_signed_in(): void
    {
        $response = $this->signIn('ann@northwind.test', [
            $this->team(self::NORTHWIND, 'Northwind Freight', 'owner', false, 'not_activated'),
            $this->team(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
        ], self::NORTHWIND);

        $response->assertStatus(403)
            ->assertSee('Dot.Auction is not switched on for Northwind Freight')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('https://infodot.test/platforms/auction', false)
            ->assertDontSee('Stack trace')
            ->assertDontSee('current-team', false);
        $this->assertGuest();
        $this->assertSame(0, User::count(), 'no account is created for a refused sign-in');
        $this->assertSame(0, Team::count());
    }

    public function test_personal_workspace_true_carries_on_and_is_never_asked_about(): void
    {
        $this->signIn('ann@northwind.test', [$this->team(self::PERSONAL, "Ann's Team", 'owner', true, null, personal: true)], self::PERSONAL)->assertRedirect(route('dashboard'));
        $this->assertSame(['personal' => true], session(EnsureCompanyEntitled::SESSION_KEY));

        $this->infodotAnswers([]);
        $this->get('/dashboard')->assertOk();
        $this->assertSame(0, $this->reads(), 'a personal workspace InfoDot admitted is not asked about');
    }

    // ---- during a session ----

    public function test_an_entitled_company_carries_on(): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'active', 'entitled' => true]]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://infodot.test/api/ecosystem/companies/'.self::NORTHWIND.'/platforms')
            && $r['platform'] === 'auction'
            && $r->hasHeader('X-Dot-Platform', 'auction')
            && $r->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    #[DataProvider('refusals')]
    public function test_a_company_without_the_platform_gets_a_403_page_with_no_data(string $status, string $heading): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $lot = Auction::factory()->create(['title' => 'Walnut Sideboard 1962', 'status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->infodotAnswers([self::NORTHWIND => ['status' => $status, 'entitled' => false]]);

        foreach (['/dashboard', '/auctions', '/auctions/'.$lot->id, '/watchlist', '/user/profile'] as $path) {
            $this->actingAs($user)->get($path)
                ->assertStatus(403)
                ->assertSee($heading)
                ->assertSee('https://infodot.test/platforms/auction', false)
                ->assertDontSee('Walnut Sideboard')
                ->assertDontSee($user->email);
        }
    }

    public static function refusals(): array
    {
        return [
            'never switched on' => ['free_while_pricing_finalised', 'Dot.Auction is not switched on for Northwind Freight'],
            'suspended' => ['suspended', 'Dot.Auction is suspended for Northwind Freight'],
            'payment needed' => ['payment_required', 'A payment is needed to keep using Dot.Auction'],
            'ended' => ['subscription_expired', 'Dot.Auction access has ended for Northwind Freight'],
        ];
    }

    public function test_nothing_can_be_changed_while_refused(): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'suspended', 'entitled' => false]]);

        $this->actingAs($user)->post('/seller/auctions', ['title' => 'Should not exist'])->assertStatus(403);
        $this->actingAs($user)->post('/livewire/update', ['components' => []], ['X-Livewire' => '1'])->assertStatus(403);
        $this->assertSame(0, Auction::count());
    }

    #[DataProvider('failures')]
    public function test_any_failure_at_infodot_lets_the_person_carry_on(string $mode): void
    {
        $user = $this->memberOf(self::NORTHWIND);

        if ($mode === 'not configured') {
            config(['ecosystem.infodot.secret' => null]);
        }

        $this->infodotAnswers(fn () => match ($mode) {
            'timeout' => throw new ConnectionException('timed out'),
            '401' => Http::response(['error' => 'invalid_request'], 401),
            '403' => Http::response(['error' => 'invalid_request'], 403),
            '404' => Http::response(['error' => 'not_found'], 404),
            '500' => Http::response('down', 500),
            'malformed' => Http::response('<html>not json</html>', 200),
            'no platforms list' => Http::response(['platform' => 'auction'], 200),
            'own row missing' => Http::response($this->platforms(self::NORTHWIND, null)),
            'entitled not a boolean' => Http::response($this->platforms(self::NORTHWIND, ['status' => 'suspended', 'entitled' => 'no'])),
            default => Http::response($this->platforms(self::NORTHWIND, ['status' => 'suspended', 'entitled' => false])),
        });

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('is not switched on')->assertDontSee('is suspended');
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame($mode === 'not configured' ? 0 : 1, $this->reads(), 'a failure is remembered for a minute, not retried on every request');
    }

    public static function failures(): array
    {
        return array_map(fn ($m) => [$m], array_combine(
            $modes = ['timeout', '401', '403', '404', '500', 'malformed', 'no platforms list', 'own row missing', 'entitled not a boolean', 'not configured'],
            $modes,
        ));
    }

    public function test_a_failure_is_forgotten_after_a_minute_and_an_answer_after_ten(): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers(fn () => Http::response('down', 500));
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->travel(61)->seconds();
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'active', 'entitled' => true]]);
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame(1, $this->reads(), 'asked again once the failure window passed');

        $this->travel(9)->minutes();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame(1, $this->reads());

        $this->travel(2)->minutes();
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'suspended', 'entitled' => false]]);
        $this->actingAs($user)->get('/dashboard')->assertStatus(403);
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'active', 'entitled' => true]]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/bids')->assertOk();

        $this->assertSame(1, $this->reads());
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        config(['ecosystem.infodot.entitlement_check' => false]);
        $user = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'suspended', 'entitled' => false]]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(0, $this->reads());
    }

    public function test_exempt_routes_make_no_call_and_stay_open(): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'suspended', 'entitled' => false]]);
        $this->actingAs($user);

        $this->get('/')->assertOk();
        $this->get('/cookies')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->post('/webhooks/paystack', [])->assertStatus(401);
        $this->post('/csp-report', [])->assertSuccessful();
        $this->getJson('/api/user')->assertOk();
        $this->post('/logout')->assertRedirect();

        $this->assertSame(0, $this->reads());
        $this->assertGuest();
    }

    public function test_a_signed_out_visitor_is_never_checked(): void
    {
        $this->infodotAnswers([]);

        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');

        $this->assertSame(0, $this->reads());
    }

    public function test_a_person_can_switch_to_another_company_that_holds_the_platform(): void
    {
        $user = $this->memberOf(self::NORTHWIND);
        $second = Team::forceCreate(['user_id' => $user->id, 'name' => 'Second Company', 'personal_team' => false, 'infodot_team_uuid' => self::SECOND]);
        $this->infodotAnswers([
            self::NORTHWIND => ['status' => 'suspended', 'entitled' => false],
            self::SECOND => ['status' => 'active', 'entitled' => true],
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Or carry on as another of your companies')
            ->assertSee('Second Company')
            ->assertSee(route('current-team.update'), false);

        $this->actingAs($user)->put(route('current-team.update'), ['team_id' => $second->id])->assertRedirect();
        $this->assertSame($second->id, $user->fresh()->current_team_id);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_personal_workspace_false_closes_the_personal_team_and_offers_the_entitled_company(): void
    {
        $this->signIn('ann@northwind.test', [
            $this->team(self::NORTHWIND, 'Northwind Freight', 'owner', true),
            $this->team(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
        ], self::PERSONAL)->assertRedirect(route('dashboard'));
        $this->assertSame(['personal' => false], session(EnsureCompanyEntitled::SESSION_KEY));

        $user = User::where('email', 'ann@northwind.test')->firstOrFail();
        $personal = $user->allTeams()->firstWhere('personal_team', true);
        $company = Team::where('infodot_team_uuid', self::NORTHWIND)->firstOrFail();
        $this->assertSame($company->id, $user->current_team_id, 'sign-in lands on the company, not the closed personal team');

        $lot = Auction::factory()->create(['title' => 'Walnut Sideboard 1962', 'status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'active', 'entitled' => true]]);
        $this->get('/dashboard')->assertOk();

        // The local personal team is not a way round the check.
        $this->put(route('current-team.update'), ['team_id' => $personal->id])->assertRedirect();
        $readsBefore = $this->reads();

        foreach (['/dashboard', '/auctions', '/auctions/'.$lot->id, '/user/profile'] as $path) {
            $this->get($path)
                ->assertStatus(403)
                ->assertSee('Dot.Auction needs a company that has switched it on')
                ->assertSee('https://infodot.test/platforms/auction', false)
                ->assertSee('Northwind Freight')
                ->assertDontSee('Walnut Sideboard');
        }
        $this->post('/livewire/update', ['components' => []], ['X-Livewire' => '1'])->assertStatus(403);
        $this->assertSame($readsBefore, $this->reads(), 'a team with no InfoDot uuid is never asked about');
        $this->assertNotNull($personal->fresh(), 'nothing in the personal workspace is deleted');

        // Back to the company that holds the platform.
        $this->put(route('current-team.update'), ['team_id' => $company->id])->assertRedirect();
        $this->get('/dashboard')->assertOk();
    }

    public function test_personal_workspace_false_with_no_company_here_shows_the_page_with_nothing_to_switch_to(): void
    {
        // An ordinary member does not create the company here, so the person is left on their personal team.
        $this->signIn('ann@northwind.test', [
            $this->team(self::NORTHWIND, 'Northwind Freight', 'editor', true),
            $this->team(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
        ], self::NORTHWIND)->assertRedirect(route('dashboard'));
        $this->infodotAnswers([]);

        $this->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Dot.Auction needs a company that has switched it on')
            ->assertDontSee('current-team', false);
        $this->assertSame(0, $this->reads());

        // Everything a signed-out person can reach, and leaving, stay open.
        $this->get('/')->assertOk();
        $this->post('/logout')->assertRedirect();
    }

    public function test_personal_workspace_absent_carries_on_as_before(): void
    {
        // An older InfoDot: no `entitled` key anywhere, so nothing is remembered.
        $this->signIn('ann@northwind.test', [
            $this->team(self::NORTHWIND, 'Northwind Freight', 'editor'),
            $this->team(self::PERSONAL, "Ann's Team", 'owner', personal: true),
        ], self::PERSONAL)->assertRedirect(route('dashboard'));
        $this->assertNull(session(EnsureCompanyEntitled::SESSION_KEY));
        $this->infodotAnswers([]);

        $this->get('/dashboard')->assertOk();
        $this->assertSame(0, $this->reads());
    }

    public function test_the_flag_off_also_leaves_a_closed_personal_workspace_open(): void
    {
        config(['ecosystem.infodot.entitlement_check' => false]);
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->withSession([EnsureCompanyEntitled::SESSION_KEY => ['personal' => false]])->get('/dashboard')->assertOk();
    }

    public function test_a_session_that_began_without_a_redeem_answer_is_not_checked(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->infodotAnswers([]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(0, $this->reads());
    }

    // ---- money already owed stays reachable ----

    /** InfoDot refuses every company; Paystack (test mode) answers initialise and verify. */
    private function refusedEverywhereWithPaystack(?string $verifyReference = null, int $cents = 123450): void
    {
        $this->enablePaystack();
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'free_while_pricing_finalised', 'entitled' => false]]);
        $routes = [
            self::READ => function (Request $request) {
                $this->reads++;

                return Http::response($this->platforms(self::NORTHWIND, ['status' => 'free_while_pricing_finalised', 'entitled' => false]));
            },
            'api.paystack.co/transaction/initialize' => $this->initializeResponse(),
        ];
        if ($verifyReference !== null) {
            $routes['api.paystack.co/transaction/verify/*'] = $this->verifyResponse($verifyReference, $cents);
        }
        Http::swap(new Factory);
        $this->reads = 0;
        Http::fake($routes);
    }

    private function intoCompany(User $user, string $uuid = self::NORTHWIND): User
    {
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Northwind Freight', 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    public function test_a_refused_companys_winner_can_open_the_won_lot_and_pay_but_not_bid_or_list(): void
    {
        [$settlement, $winner] = $this->settlement(123450);
        $winner = $this->intoCompany($winner);
        $payment = $this->attempt($settlement);
        $this->refusedEverywhereWithPaystack($payment->reference);
        $live = Auction::factory()->create(['status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->actingAs($winner);

        $this->get(route('buyer.won'))->assertOk();
        $this->get(route('buyer.bids'))->assertOk();
        $this->get(route('settlements.index'))->assertOk();
        $this->get(route('settlements.show', $settlement))->assertOk()->assertSee('Pay now');

        $this->post(route('settlements.pay', $settlement))->assertRedirectContains('https://checkout.paystack.com/');
        $this->get(route('settlements.payment.callback', $settlement))->assertRedirect(route('settlements.show', $settlement));
        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status, 'the winner paid inside a refused company');
        $this->assertSame(0, $this->reads(), 'the exempt pages never ask InfoDot');

        // New auction activity stays closed: bidding (a Livewire action), a lot page, a new lot, the watchlist.
        $this->post('/livewire/update', ['components' => []], ['X-Livewire' => '1'])->assertStatus(403);
        $this->get(route('auctions.show', $live))->assertStatus(403);
        $this->get(route('seller.auctions.create'))->assertStatus(403);
        $this->post(route('seller.auctions.store'), ['title' => 'Should not exist'])->assertStatus(403);
        $this->get(route('buyer.watchlist'))->assertStatus(403);
        $this->assertSame(0, Auction::where('title', 'Should not exist')->count());
    }

    public function test_a_refused_companys_seller_can_see_and_handle_a_sold_lot_but_not_list_a_new_one(): void
    {
        [$settlement, , $seller] = $this->settlement(100000, ['status' => AuctionSettlement::PAYMENT_OVERDUE, 'overdue_at' => now()->subDay(), 'payment_due_at' => now()->subDay()]);
        $seller = $this->intoCompany($seller);
        $this->refusedEverywhereWithPaystack();
        $this->actingAs($seller);

        $this->get(route('seller.auctions.index'))->assertOk();
        $this->get(route('settlements.index', ['role' => 'selling']))->assertOk();
        $this->get(route('settlements.show', $settlement))->assertOk()->assertSee('Cancel this sale');
        $this->post(route('settlements.cancel', $settlement))->assertRedirect(route('settlements.show', $settlement));
        $this->assertSame(AuctionSettlement::CANCELLED, $settlement->fresh()->status);

        $lot = $settlement->auction;
        $this->get(route('seller.auctions.create'))->assertStatus(403);
        $this->post(route('seller.auctions.store'), ['title' => 'Should not exist'])->assertStatus(403);
        $this->get(route('seller.auctions.edit', $lot))->assertStatus(403);
        $this->put(route('seller.auctions.update', $lot), ['title' => 'Changed'])->assertStatus(403);
        $this->post(route('seller.auctions.publish', $lot))->assertStatus(403);
        $this->post(route('seller.auctions.cancel', $lot))->assertStatus(403);
        $this->delete(route('seller.auctions.destroy', $lot))->assertStatus(403);
        $this->assertNotSame('Changed', $lot->fresh()->title);
    }

    public function test_another_persons_sale_is_still_not_reachable_in_a_refused_company(): void
    {
        [$settlement] = $this->settlement();
        $stranger = $this->memberOf(self::NORTHWIND);
        $this->refusedEverywhereWithPaystack();
        $this->actingAs($stranger);

        $this->get(route('settlements.show', $settlement))->assertNotFound();
        $this->post(route('settlements.pay', $settlement))->assertNotFound();
        $this->get(route('settlements.payment.callback', $settlement))->assertNotFound();
        $this->post(route('settlements.cancel', $settlement))->assertNotFound();
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_a_signed_out_visitor_still_cannot_reach_the_settlement_pages(): void
    {
        [$settlement] = $this->settlement();

        $this->get(route('settlements.show', $settlement))->assertRedirect(route('login'));
        $this->post(route('settlements.pay', $settlement))->assertRedirect(route('login'));
    }

    public function test_a_closed_personal_workspace_keeps_the_same_exemptions_and_no_more(): void
    {
        [$settlement, $winner, $seller] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $this->refusedEverywhereWithPaystack($payment->reference);
        $closed = [EnsureCompanyEntitled::SESSION_KEY => ['personal' => false]];
        $lot = Auction::factory()->create(['status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        // Winner: pays from the personal workspace.
        $this->withSession($closed)->actingAs($winner);
        $this->get(route('buyer.won'))->assertOk();
        $this->get(route('buyer.bids'))->assertOk();
        $this->get(route('settlements.show', $settlement))->assertOk();
        $this->post(route('settlements.pay', $settlement))->assertRedirectContains('https://checkout.paystack.com/');
        $this->get(route('settlements.payment.callback', $settlement))->assertRedirect(route('settlements.show', $settlement));
        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
        $this->get(route('auctions.show', $lot))->assertStatus(403)->assertSee('needs a company that has switched it on');
        $this->get(route('seller.auctions.create'))->assertStatus(403);
        $this->post('/livewire/update', ['components' => []], ['X-Livewire' => '1'])->assertStatus(403);

        // Seller: sees the sold lot, cannot list a new one.
        $this->asGuest()->withSession($closed)->actingAs($seller);
        $this->get(route('seller.auctions.index'))->assertOk();
        $this->get(route('settlements.show', $settlement))->assertOk();
        $this->get(route('seller.auctions.create'))->assertStatus(403);
        $this->assertSame(0, $this->reads(), 'a team with no InfoDot uuid is never asked about');
    }

    // ---- running auctions ----

    public function test_the_scheduler_still_settles_an_auction_won_by_someone_whose_company_lost_the_platform(): void
    {
        Notification::fake();
        $winner = $this->memberOf(self::NORTHWIND);
        $this->infodotAnswers([self::NORTHWIND => ['status' => 'suspended', 'entitled' => false]]);
        $this->actingAs($winner)->get('/dashboard')->assertStatus(403);

        $auction = Auction::factory()->create(['status' => 'ended', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour(), 'current_price' => '150.00']);
        Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => $winner->id, 'amount' => '150.00', 'is_winning' => true]);

        $this->artisan('auction:repair-unsettled')->assertSuccessful();
        $this->artisan('auction:settle-ended')->assertSuccessful();
        $this->artisan('auction:mark-overdue-payments')->assertSuccessful();

        $settlement = AuctionSettlement::sole();
        $this->assertSame($winner->id, $settlement->winner_id);
        $this->assertSame(1, $this->reads(), 'only the page request asked InfoDot; the commands never do');
    }
}
