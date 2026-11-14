<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The header carries the shared Dot UI (dot-ui): the search pill and the platform switcher. Presentation changed; the
 * search form, its route, parameters, results and the bidding pages did not, and the switcher only ever shows what
 * InfoDot says the CURRENT company holds, linking through InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'auction', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['auction', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['auction' => 'Dot.Auction', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'auction', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function lot(string $title, string $status = 'active'): Auction
    {
        return Auction::forceCreate([
            'seller_id' => User::factory()->create()->id, 'title' => $title, 'description' => 'A lot.', 'starting_price' => 100, 'current_price' => 100,
            'bid_increment' => 10, 'status' => $status, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
        ]);
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    // ── The search pill ──────────────────────────────────────────────────────

    public function test_the_header_search_keeps_its_form_route_and_parameter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('auctions.index', ['q' => 'Camera']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form role="search" method="get"\s+action="([^"]+)"\s+id="shell-search-form"/', $html, $form), 'a GET search form');
        $this->assertSame(route('auctions.index'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\btype="search"[^>]*\bname="q"[^>]*\bvalue="Camera"/', $html, 'same parameter, current value kept');
        $this->assertStringContainsString('placeholder="Search lots"', $html);
        $this->assertStringContainsString('class="dsr-input"', $html, 'the shared pill');
        $this->assertStringNotContainsString('<form class="sh-search"', $html, 'the old search form is gone');
        $this->assertStringNotContainsString('dsr-kbd', $html, 'no invented shortcut hint');
    }

    public function test_on_browse_the_pill_keeps_the_tab_category_and_sort_inside_its_form(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('auctions.index', ['q' => 'Camera', 'state' => 'live', 'sort' => 'newest']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form role="search".*?id="shell-search-form".*?<\/form>/s', $html, $form));
        $this->assertStringContainsString('<input type="hidden" name="state" value="live">', $form[0], 'inside the form, no form attribute');
        $this->assertStringContainsString('<input type="hidden" name="sort" value="newest">', $form[0]);
        $this->assertStringNotContainsString('name="category"', $form[0]);
        $this->assertStringNotContainsString('form="shell-search-form"', $html);

        $elsewhere = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('type="hidden" name="state"', substr($elsewhere, (int) strpos($elsewhere, 'id="shell-search-form"'), 900), 'only Browse carries the current filters');
    }

    public function test_a_guest_sees_no_search_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    public function test_search_results_are_unchanged_and_drafts_stay_hidden(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $this->lot('Brass Telescope');
        $this->lot('Oak Writing Desk');
        $this->lot('Brass Draft Compass', 'draft');

        $this->actingAs($owner)->get(route('auctions.index', ['q' => 'brass']))->assertOk()
            ->assertSee('Brass Telescope')->assertDontSee('Oak Writing Desk')->assertDontSee('Brass Draft Compass');
    }

    public function test_the_in_page_search_on_browse_is_the_shared_compact_filter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index', ['q' => 'Camera']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="[^"]*dsr-filter[^"]*"[^>]*>/', $html);
        $this->assertStringContainsString('id="browse-q"', $html);
        $this->assertStringContainsString('aria-label="Search lots"', $html);
        $this->assertStringContainsString('maxlength="100"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('auctions.index'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="auction"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Auction', $here[0]);

        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);
        $this->assertStringContainsString('Not switched on (3)', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index'))->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'auction');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['auction', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['auction', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index'))->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get(route('auctions.index'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('opened through InfoDot', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('auctions.index'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index'))->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The script carries the request's nonce and the policy gained no origin.
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('#<script src="[^"]*dot-ui/js/dot-switcher\.js" nonce="[^"]+" defer>#', $html);
        $this->assertStringNotContainsString('http', preg_replace('#https?://[^\s;]*report[^\s;]*#', '', $policy), 'the policy lists no external origin');
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('auctions.index'))->getContent();

        $this->assertSame(1, preg_match('/<div class="dsr[ "][^>]*data-dsr.*?<\/form>\s*<\/div>/s', $html, $pill));
        foreach ([$pill[0], $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }

    // ── The auction itself is untouched ─────────────────────────────────────

    public function test_the_lot_page_still_carries_its_bid_panel_and_the_header(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $lot = $this->lot('Walnut Sideboard');

        $html = $this->actingAs($owner)->get(route('auctions.show', $lot))->assertOk()->getContent();

        $this->assertStringContainsString('Walnut Sideboard', $html);
        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('wire:id', $html, 'the Livewire bid panel still renders');
    }
}
