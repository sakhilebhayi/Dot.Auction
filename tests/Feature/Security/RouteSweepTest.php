<?php

namespace Tests\Feature\Security;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Services\Auctions\AccountDeletionBlocked;
use App\Services\Auctions\AccountEraser;
use App\Services\Auctions\AuctionImageManager;
use App\Services\Auctions\AuctionImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * R-42 security sweep: every route, every kind of person.
 *
 * One world with a person in every position the product has (stranger, leading bidder, outbid bidder, the seller of the
 * lot, the seller of another lot, a member of the seller's team, winner, loser, operator, an erased account), and one
 * table of "this route, this person, this status". A route added without a row here fails the inventory test, so a new
 * page cannot ship without someone deciding who may open it.
 *
 * Mutating routes are also checked for side effects: whatever a forbidden person posts, a fingerprint of the money and
 * bid tables is unchanged afterwards.
 */
class RouteSweepTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private const SECRET_RESERVE = '543.21';

    /** @var array<string, User|null> */
    private array $who = [];

    private Auction $lot;

    private Auction $draft;

    private Auction $otherLot;

    private Auction $endedLot;

    private AuctionSettlement $sale;

    private AuctionImage $lotImage;

    private AuctionImage $draftImage;

    private AuctionImage $otherImage;

    private ReserveNotMetProposal $proposal;

    private DatabaseNotification $leaderNote;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AuctionImage::DISK);
        $this->buildWorld();
    }

    private function person(string $name): User
    {
        return User::factory()->withPersonalTeam()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@sweep.test']);
    }

    private function photo(Auction $auction): AuctionImage
    {
        return app(AuctionImageManager::class)->add($auction, [UploadedFile::fake()->image('p.jpg', 40, 30)])[0];
    }

    private function buildWorld(): void
    {
        $w = &$this->who;
        $w['guest'] = null;
        $w['stranger'] = $this->person('Stranger Newcomer');
        $w['seller'] = $this->person('Sandra Seller');
        $w['otherSeller'] = $this->person('Oscar Otherseller');
        $w['leader'] = $this->person('Lerato Leaderfull');
        $w['outbid'] = $this->person('Olivia Outbidfull');
        $w['member'] = $this->person('Mandla Memberfull');
        $w['operator'] = User::factory()->withPersonalTeam()->platformOperator()->create(['name' => 'Naledi Operatorfull']);
        $w['winner'] = $this->person('Winston Winnerfull');
        $w['loser'] = $this->person('Lindiwe Loserfull');
        $w['seller']->ownedTeams()->first()->users()->attach($w['member'], ['role' => 'admin']);

        $this->lot = Auction::factory()->create(['seller_id' => $w['seller']->id, 'title' => 'Sweep lot', 'reserve_price' => self::SECRET_RESERVE, 'starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $this->draft = Auction::factory()->draft()->create(['seller_id' => $w['seller']->id, 'title' => 'Sweep secret draft']);
        $this->otherLot = Auction::factory()->create(['seller_id' => $w['otherSeller']->id, 'title' => 'Other sellers lot']);

        $this->bid($this->lot, $w['outbid'], '110.00');
        $this->bid($this->lot, $w['leader'], '120.00');

        $this->endedLot = Auction::factory()->ended()->create(['seller_id' => $w['seller']->id, 'title' => 'Sweep ended lot', 'status' => 'ended']);
        $this->bid($this->endedLot, $w['loser'], '150.00', false);
        $this->bid($this->endedLot, $w['winner'], '200.00');
        $this->sale = AuctionSettlement::forceCreate([
            'auction_id' => $this->endedLot->id, 'winning_bid_id' => Bid::where('auction_id', $this->endedLot->id)->where('is_winning', true)->value('id'),
            'seller_id' => $w['seller']->id, 'winner_id' => $w['winner']->id, 'amount_cents' => 20000, 'currency' => 'ZAR',
            'status' => AuctionSettlement::AWAITING_PAYMENT, 'payment_due_at' => now()->addDays(3),
        ]);

        $reserveLot = Auction::factory()->create(['seller_id' => $w['seller']->id, 'status' => 'pending_review', 'reserve_price' => '900.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour()]);
        $reserveBid = $this->bid($reserveLot, $w['leader'], '300.00');
        $this->proposal = ReserveNotMetProposal::forceCreate(['auction_id' => $reserveLot->id, 'bid_id' => $reserveBid->id, 'reserve_price' => '900.00', 'status' => 'pending']);

        $this->lotImage = $this->photo($this->lot);
        $this->draftImage = $this->photo($this->draft);
        $this->otherImage = $this->photo($this->otherLot);

        $this->leaderNote = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $w['leader']->id, 'data' => ['url' => '/auctions'],
        ]);
    }

    private function bid(Auction $lot, User $bidder, string $amount, bool $winning = true): Bid
    {
        if ($winning) {
            Bid::query()->where('auction_id', $lot->id)->where('is_winning', true)->update(['is_winning' => false]);
        }
        $bid = Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $bidder->id, 'amount' => $amount, 'is_winning' => $winning]);
        $lot->forceFill(['current_price' => $amount])->save();

        return $bid;
    }

    private function as(string $role): static
    {
        $this->app['auth']->forgetGuards();
        if ($this->who[$role] !== null) {
            $this->actingAs($this->who[$role]);
        }

        return $this;
    }

    /** The two tables a forbidden request must never change. */
    private function fingerprint(): string
    {
        return md5(json_encode([
            DB::table('auctions')->orderBy('id')->get(),
            DB::table('bids')->orderBy('id')->get(),
            DB::table('auction_settlements')->orderBy('id')->get(),
            DB::table('settlement_payments')->orderBy('id')->get(),
            DB::table('reserve_not_met_proposals')->orderBy('id')->get(),
            DB::table('auction_images')->orderBy('id')->get(),
            DB::table('watchlists')->orderBy('user_id')->get(),
        ]));
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Inventory: nothing is reachable that nobody decided about.
    // ----------------------------------------------------------------------------------------------------------------

    /** Routes that answer a guest on purpose (public pages, health, webhooks, sign-in machinery). */
    private const PUBLIC = [
        'GET /', 'GET /cookies', 'GET /privacy-policy', 'GET /terms-of-service', 'GET /up', 'GET /up/realtime',
        'POST /csp-report', 'POST /webhooks/paystack', 'POST /auth/ecosystem', 'GET /auth/ecosystem',
        'GET /login', 'POST /login', 'GET /forgot-password', 'POST /forgot-password', 'GET /reset-password/{token}', 'POST /reset-password',
        'GET /two-factor-challenge', 'POST /two-factor-challenge', 'GET /register', 'GET /sanctum/csrf-cookie',
        'GET /livewire/livewire.js', 'GET /livewire/livewire.min.js.map', 'POST /livewire/update', 'POST /livewire/upload-file',
        'GET /livewire/preview-file/{filename}', 'GET /broadcasting/auth', 'POST /broadcasting/auth',
        // Laravel's own: the storage fallback (only with a local public disk) and the dev-only Boost log sink.
        'GET /storage/{path}', 'PUT /storage/{path}',
        'POST /_boost/browser-logs',
        // Local/testing only; absent in production (see test_dev_routes_exist_only_in_local_and_testing).
        'GET /dev/kit', 'GET /dev/login/{who}', 'GET /dev/logout',
    ];

    /** @return array<int, string> "METHOD /uri" for every route, HEAD folded away */
    private function inventory(): array
    {
        $rows = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $rows[] = $method.' /'.ltrim($route->uri(), '/');
            }
        }
        sort($rows);

        return $rows;
    }

    public function test_every_route_is_either_a_known_public_route_or_closed_to_a_guest(): void
    {
        $this->app['auth']->forgetGuards();
        $open = [];

        foreach ($this->inventory() as $row) {
            [$method, $uri] = explode(' ', $row, 2);

            if (in_array($row, self::PUBLIC, true) || str_starts_with($uri, '/user/') || $uri === '/register' || $uri === '/logout' || $uri === '/current-team') {
                continue;
            }

            $filled = preg_replace_callback('/\{[^}]+\}/', fn ($m) => str_contains($m[0], 'settlement') || str_contains($m[0], 'notification') ? '00000000-0000-0000-0000-000000000000' : '1', $uri);
            $response = $this->call($method, $filled);
            $status = $response->getStatusCode();

            if (! ($status === 302 && str_ends_with((string) $response->headers->get('Location'), '/login')) && $status !== 401 && $status !== 403) {
                $open[] = "{$row} answered a guest {$status}";
            }
        }

        $this->assertSame([], $open, "Routes open to a guest that are not on the PUBLIC list:\n".implode("\n", $open));
    }

    public function test_the_public_allowlist_has_no_stale_rows(): void
    {
        $inventory = $this->inventory();

        foreach (self::PUBLIC as $row) {
            // The Boost sink and the dev block exist only on some installs; everything else must be a real route.
            if (str_contains($row, '_boost') || str_contains($row, '/storage/') || str_contains($row, '/dev/')) {
                continue;
            }
            $this->assertContains($row, $inventory, "{$row} is allow-listed but not a route any more; remove it.");
        }
    }

    public function test_the_authenticated_account_and_team_routes_need_a_session_too(): void
    {
        $this->app['auth']->forgetGuards();

        foreach (['GET /user/profile', 'GET /teams/create', 'GET /dashboard', 'GET /user/two-factor-qr-code', 'GET /user/two-factor-recovery-codes', 'GET /user/two-factor-secret-key', 'GET /user/confirmed-password-status'] as $row) {
            [$method, $uri] = explode(' ', $row);
            $r = $this->call($method, $uri);
            $this->assertTrue(in_array($r->getStatusCode(), [302, 401, 403], true), "{$row} answered a guest {$r->getStatusCode()}");
        }

        foreach ([['PUT', '/user/profile-information'], ['PUT', '/user/password'], ['POST', '/user/two-factor-authentication'], ['PUT', '/current-team'], ['POST', '/logout']] as [$m, $u]) {
            $r = $this->call($m, $u);
            $this->assertTrue(in_array($r->getStatusCode(), [302, 401, 403, 419], true), "{$m} {$u} answered a guest {$r->getStatusCode()}");
        }
    }

    public function test_the_api_token_page_and_the_scaffold_api_are_off(): void
    {
        $this->as('seller')->get('/user/api-tokens')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertStatus(401);
        $this->assertFalse(Jetstream::hasApiFeatures());
    }

    public function test_dev_routes_exist_only_in_local_and_testing(): void
    {
        $script = base_path('tests/Support/route-inventory.php');
        $this->assertFileExists($script);

        foreach (['production', 'staging'] as $env) {
            $out = shell_exec('APP_ENV='.$env.' APP_DEBUG=false php '.escapeshellarg($script).' 2>&1');
            $this->assertNotNull($out);
            $this->assertStringNotContainsString('dev/', (string) $out, "{$env} registers a /dev route:\n{$out}");
            $this->assertStringContainsString('auctions', (string) $out, "route inventory did not run in {$env}:\n{$out}");
        }
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Read pages: who gets what.
    // ----------------------------------------------------------------------------------------------------------------

    private const SIGNED_IN = ['stranger', 'seller', 'otherSeller', 'leader', 'outbid', 'member', 'operator', 'winner', 'loser'];

    public function test_pages_that_everyone_signed_in_may_open(): void
    {
        $urls = [
            '/dashboard', '/auctions', '/auctions?q=sweep&state=live&sort=newest', '/bids', '/watchlist', '/won', '/notifications',
            '/seller/auctions', '/seller/auctions?state=live', '/seller/auctions/create', '/settlements', '/settlements?role=buying', '/user/profile',
        ];

        foreach (self::SIGNED_IN as $role) {
            $this->as($role);
            foreach ($urls as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_a_published_lot_and_its_photo_are_open_to_every_signed_in_person_and_a_draft_to_nobody_else(): void
    {
        foreach (self::SIGNED_IN as $role) {
            $this->as($role);
            $this->get(route('auctions.show', $this->lot))->assertOk();
            $this->get(route('auctions.images.show', [$this->lot, $this->lotImage]))->assertOk();

            $expected = $role === 'seller' ? 200 : 404;
            $this->get(route('auctions.show', $this->draft))->assertStatus($expected);
            $this->get(route('auctions.images.show', [$this->draft, $this->draftImage]))->assertStatus($expected);
        }

        // A draft answers exactly like an id that does not exist: nothing to count.
        $this->as('stranger');
        $this->assertSame($this->get(route('auctions.show', $this->draft))->getStatusCode(), $this->get('/auctions/99999999')->getStatusCode());
    }

    public function test_a_photo_cannot_be_read_through_another_lots_url_or_with_a_traversing_id(): void
    {
        $this->as('stranger');
        // A real photo, a real lot, but not the photo's lot: scoped binding refuses it.
        $this->get(route('auctions.images.show', [$this->lot, $this->otherImage]))->assertNotFound();
        $this->get(route('auctions.images.show', [$this->otherLot, $this->draftImage]))->assertNotFound();
        $this->get('/auctions/'.$this->lot->id.'/images/..%2F..%2F.env')->assertNotFound();
        $this->get('/auctions/'.$this->lot->id.'/images/1/../../../etc/passwd')->assertNotFound();
        $this->get('/auctions/..%2F1/images/'.$this->lotImage->id)->assertNotFound();

        // A stored path that tries to leave the disk never reaches a file outside it (the path is server generated;
        // this proves the controller does not trust it blindly either).
        $this->lotImage->forceFill(['path' => '../../.env'])->save();
        $this->get(route('auctions.images.show', [$this->lot, $this->lotImage]))->assertNotFound();
    }

    public function test_the_photo_response_cannot_be_turned_into_a_page(): void
    {
        $this->as('stranger');
        $r = $this->get(route('auctions.images.show', [$this->lot, $this->lotImage]))->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame(AuctionImageVariants::format()[1], $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
    }

    public function test_the_lot_page_never_shows_the_reserve_or_another_persons_identity(): void
    {
        foreach (['stranger', 'leader', 'outbid', 'otherSeller', 'member', 'operator', 'winner', 'loser'] as $role) {
            $html = $this->as($role)->get(route('auctions.show', $this->lot))->assertOk()->getContent();

            $this->assertStringNotContainsString(self::SECRET_RESERVE, $html, "{$role} can read the reserve price");
            foreach ($this->who as $other => $person) {
                if ($person !== null && $other !== $role) {
                    $this->assertStringNotContainsString($person->email, $html, "{$role} can read the e-mail address of {$other}");
                }
            }
            // (The seller's own name is shown on the lot by design; see docs/R42_SECURITY_PERF.md, observation O-1.)
            foreach (['Olivia Outbidfull', 'Lerato Leaderfull'] as $fullName) {
                if ($this->who[$role]->name === $fullName) {
                    continue; // one's own name is in one's own menu
                }
                $this->assertStringNotContainsString($fullName, $html, "{$role} can read the full name {$fullName}");
            }
        }
    }

    public function test_the_buyer_pages_list_only_the_signed_in_persons_own_rows(): void
    {
        // The stranger has no bid, watch or win: another person's lots must not appear on any of their buyer pages.
        foreach (['/bids', '/watchlist', '/won', '/notifications'] as $url) {
            $html = $this->as('stranger')->get($url)->assertOk()->getContent();
            foreach (['Sweep lot', 'Sweep ended lot', 'Other sellers lot'] as $title) {
                $this->assertStringNotContainsString($title, $html, "{$url} shows {$title} to someone with no part in it");
            }
        }

        $this->as('winner')->get('/won')->assertOk()->assertSee('Sweep ended lot');
        $this->as('loser')->get('/won')->assertOk()->assertDontSee('Sweep ended lot');
        $this->as('leader')->get('/bids')->assertOk()->assertSee('Sweep lot');
        $this->as('winner')->get('/bids')->assertOk()->assertDontSee('Other sellers lot');
    }

    public function test_the_seller_list_shows_only_the_sellers_lots_and_ignores_hostile_filters(): void
    {
        $html = $this->as('otherSeller')->get('/seller/auctions')->assertOk()->getContent();
        $this->assertStringNotContainsString('Sweep lot', $html);
        $this->assertStringNotContainsString('Sweep secret draft', $html);

        $html = $this->as('member')->get('/seller/auctions')->assertOk()->getContent();
        $this->assertStringNotContainsString('Sweep lot', $html, 'a team member does not see the owner\'s lots');

        foreach (['live', 'ending', 'draft', 'pending', "' OR 1=1 --", '<script>alert(1)</script>', str_repeat('a', 5000), '../../etc', '0', '[]'] as $state) {
            $r = $this->as('seller')->get('/seller/auctions?state='.urlencode($state))->assertOk();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $r->getContent());
        }
        $this->as('seller')->get('/seller/auctions?state[]=live')->assertOk();
        $this->as('seller')->get('/seller/auctions?page=-5')->assertOk();
        $this->as('seller')->get('/seller/auctions?page=99999999999999999999')->assertOk();
    }

    // ----------------------------------------------------------------------------------------------------------------
    // The seller's own screens and actions.
    // ----------------------------------------------------------------------------------------------------------------

    public function test_seller_screens_and_actions_belong_to_the_seller_alone(): void
    {
        $before = $this->fingerprint();
        $payload = ['title' => 'Hijacked', 'description' => 'x', 'starting_price' => '1', 'bid_increment' => '1', 'starts_at' => now()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDay()->format('Y-m-d\TH:i')];

        foreach (['stranger', 'otherSeller', 'leader', 'outbid', 'member', 'operator', 'winner', 'loser'] as $role) {
            $this->as($role);

            // A live lot of somebody else: 403. A draft of somebody else: 404, like a lot that does not exist.
            foreach ([[$this->lot, 403], [$this->draft, 404]] as [$lot, $status]) {
                $this->get(route('seller.auctions.edit', $lot))->assertStatus($status);
                $this->put(route('seller.auctions.update', $lot), $payload)->assertStatus($status);
                $this->post(route('seller.auctions.publish', $lot))->assertStatus($status);
                $this->post(route('seller.auctions.cancel', $lot))->assertStatus($status);
                $this->delete(route('seller.auctions.destroy', $lot))->assertStatus($status);
                $this->post(route('seller.auctions.images.store', $lot), ['images' => [UploadedFile::fake()->image('a.jpg')]])->assertStatus($status);
                $image = $lot->is($this->lot) ? $this->lotImage : $this->draftImage;
                $this->delete(route('seller.auctions.images.destroy', [$lot, $image]))->assertStatus($status);
            }
        }

        $this->assertSame($before, $this->fingerprint(), 'a forbidden request changed the auction, bid, sale, photo or watch tables');
    }

    public function test_a_seller_cannot_use_one_lots_url_to_delete_another_lots_photo(): void
    {
        $this->as('seller');
        $myOther = Auction::factory()->create(['seller_id' => $this->who['seller']->id]);
        $this->delete(route('seller.auctions.images.destroy', [$myOther, $this->lotImage]))->assertNotFound();
        $this->delete(route('seller.auctions.images.destroy', [$this->lot, $this->otherImage]))->assertNotFound();
        $this->assertDatabaseHas('auction_images', ['id' => $this->lotImage->id]);
        $this->assertDatabaseHas('auction_images', ['id' => $this->otherImage->id]);
    }

    public function test_the_reserve_decision_belongs_to_the_seller_of_that_lot(): void
    {
        foreach (['stranger', 'otherSeller', 'leader', 'member', 'operator'] as $role) {
            $this->as($role);
            $this->assertFalse($this->who[$role]->can('review', $this->proposal), "{$role} may decide a reserve");
        }
        $this->assertTrue($this->who['seller']->can('review', $this->proposal));
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Sales and payments.
    // ----------------------------------------------------------------------------------------------------------------

    public function test_a_sale_is_open_to_its_two_parties_and_invisible_to_everyone_else(): void
    {
        $this->enablePaystack();
        $before = $this->fingerprint();

        foreach (self::SIGNED_IN as $role) {
            $this->as($role);
            $party = in_array($role, ['seller', 'winner'], true);

            $this->get(route('settlements.show', $this->sale))->assertStatus($party ? 200 : 404);
            $this->get(route('settlements.index'))->assertOk();
            $html = $this->get(route('settlements.index'))->getContent();
            $this->assertSame($party, str_contains($html, route('settlements.show', $this->sale)), "{$role}: sale link in the list");

            if (! $party) {
                $this->post(route('settlements.pay', $this->sale))->assertNotFound();
                $this->get(route('settlements.payment.callback', $this->sale))->assertNotFound();
                $this->post(route('settlements.cancel', $this->sale))->assertNotFound();
            }
        }

        // The operator sees the payments queue, not the sale itself.
        $this->as('operator')->get(route('settlements.show', $this->sale))->assertNotFound();
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_only_the_winner_pays_and_only_the_seller_cancels(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['*' => fn () => throw new \RuntimeException('no gateway call may happen for a forbidden request')]);

        $this->as('seller')->post(route('settlements.pay', $this->sale))->assertForbidden();
        $this->get(route('settlements.payment.callback', $this->sale))->assertForbidden();
        $this->as('winner')->post(route('settlements.cancel', $this->sale))->assertForbidden();

        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $this->sale->fresh()->status);
    }

    public function test_the_operator_page_is_for_operators_only(): void
    {
        foreach (self::SIGNED_IN as $role) {
            $this->as($role)->get(route('operator.settlement-payments.index'))->assertStatus($role === 'operator' ? 200 : 403);
        }
        foreach (['', '?status=held', '?show=attention', '?status=%27%20or%201=1', '?status[]=x'] as $query) {
            $this->as('operator')->get(route('operator.settlement-payments.index').$query)->assertOk();
        }
    }

    public function test_the_payment_callback_ignores_the_query_string_and_never_redirects_off_site(): void
    {
        $this->enablePaystack();
        $this->fakeHttp();

        $r = $this->as('winner')->get(route('settlements.payment.callback', $this->sale).'?redirect=https://evil.example/&next=//evil.example&callback_url=https://evil.example&status=success&reference=AUC-FORGED');
        $this->assertTrue($r->isRedirect());
        $this->assertStringStartsWith(route('settlements.show', $this->sale), (string) $r->headers->get('Location'));
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $this->sale->fresh()->status, 'a forged return URL cannot mark a sale paid');
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Notifications: own rows only, same-site redirects only.
    // ----------------------------------------------------------------------------------------------------------------

    public function test_a_notification_can_only_be_opened_by_its_owner(): void
    {
        $id = $this->leaderNote->id;

        foreach (['stranger', 'seller', 'outbid', 'operator'] as $role) {
            $this->as($role)->post(route('notifications.open', $id))->assertNotFound();
        }
        $this->assertNull($this->leaderNote->fresh()->read_at, 'a stranger marked it read');

        $this->as('leader')->post(route('notifications.open', $id))->assertRedirect();
        $this->assertNotNull($this->leaderNote->fresh()->read_at);
    }

    public function test_the_notification_redirect_only_ever_goes_to_this_site(): void
    {
        $leader = $this->who['leader'];

        $hostile = [
            'javascript:alert(1)', 'JaVaScRiPt:alert(1)', '//evil.example/path', '///evil.example', '\\\\evil.example', '/\\evil.example',
            'https://evil.example/auctions/1', 'http://evil.example', 'data:text/html,<script>alert(1)</script>', "/ok\r\nLocation: https://evil.example",
            'https://app.example.test.evil.example/', 'https://evil.example/@app.example.test', "/\t/evil.example", 'ftp://app.example.test/x', '',
        ];

        foreach ($hostile as $url) {
            $n = DatabaseNotification::query()->create([
                'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $leader->id, 'data' => ['url' => $url],
            ]);
            $r = $this->as('leader')->post(route('notifications.open', $n->id));
            $location = (string) $r->headers->get('Location');
            $this->assertStringStartsWith(url('/'), $location, 'redirected to '.json_encode($url).' -> '.$location);
            $this->assertStringNotContainsString('evil', $location);
            $this->assertStringNotContainsString("\n", $location);
        }

        // And an honest one still works.
        $n = DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $leader->id, 'data' => ['url' => '/auctions/'.$this->lot->id]]);
        $this->as('leader')->post(route('notifications.open', $n->id))->assertRedirect(url('/auctions/'.$this->lot->id));
    }

    public function test_watchlist_removal_works_on_own_rows_and_a_draft_title_never_leaks(): void
    {
        $this->as('stranger');
        $this->delete(route('buyer.watchlist.destroy', $this->draft))->assertNotFound();
        $this->delete(route('buyer.watchlist.destroy', 99999999))->assertNotFound();
        $this->delete(route('buyer.watchlist.destroy', $this->lot))->assertRedirect(route('buyer.watchlist'));
    }

    public function test_a_team_invitation_cannot_be_accepted_without_its_signed_link_or_by_the_wrong_person(): void
    {
        $team = $this->who['seller']->ownedTeams()->first();
        $invitation = $team->teamInvitations()->create(['email' => $this->who['stranger']->email, 'role' => 'editor']);

        // No signature: refused, whoever asks.
        $this->as('stranger')->get(route('team-invitations.accept', $invitation))->assertForbidden();
        $this->as('seller')->get(route('team-invitations.accept', $invitation))->assertForbidden();

        // A correct signature accepts it for the signed-in person (Jetstream's own rule); nobody joins by guessing the id.
        $this->assertFalse($this->who['stranger']->belongsToTeam($team));
        $this->as('guest')->get(URL::signedRoute('team-invitations.accept', $invitation))->assertRedirect();
        $this->assertFalse($this->who['stranger']->fresh()->belongsToTeam($team->fresh()), 'a guest with a signed link joins nothing');
    }

    // ----------------------------------------------------------------------------------------------------------------
    // An erased account.
    // ----------------------------------------------------------------------------------------------------------------

    public function test_an_erased_account_keeps_its_bids_but_cannot_come_back_in(): void
    {
        $loser = $this->who['loser'];
        $email = $loser->email;
        $bidIds = Bid::where('bidder_id', $loser->id)->pluck('id')->all();
        $this->assertNotEmpty($bidIds);

        app(AccountEraser::class)->erase($loser);

        $this->assertSame($bidIds, Bid::where('bidder_id', $loser->id)->pluck('id')->all(), 'erasing an account must not erase bid history');
        $this->assertSame(0, User::where('email', $email)->count(), 'the e-mail address is released for a new account');

        // Neither the old address nor the placeholder with the old password signs anyone in.
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => $email, 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('/login', ['email' => $loser->fresh()->email, 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();

        // A new account on the freed address is a different person: none of the old bids are theirs.
        $new = User::factory()->create(['email' => $email]);
        $this->assertNotSame($loser->id, $new->id);
        $this->assertSame(0, Bid::where('bidder_id', $new->id)->count());
    }

    public function test_an_erased_account_is_not_a_back_door_to_the_sale_pages(): void
    {
        $winner = $this->who['winner'];
        // Blocked while a sale is unpaid: erasing is refused, so nobody can shed a debt by deleting the account.
        $this->expectException(AccountDeletionBlocked::class);

        app(AccountEraser::class)->erase($winner);
    }
}
