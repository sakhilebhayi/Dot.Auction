<?php

namespace Tests\Feature\R52;

use App\Models\Auction;
use App\Models\Team;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-52: Roboto is the one text face (self-hosted through dot-ui), the shared dot-ui header replaces the old top bar and the phone menu sheet,
 * and nothing external or inline is added. Chivo Mono stays for exactly the clock, lot numbers and payment references.
 */
class RobotoAndHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function root(string $relative): string
    {
        return dirname(__DIR__, 3).'/'.$relative;
    }

    /** @return array<string, array{0: string}> */
    public static function signedInPages(): array
    {
        return ['dashboard' => ['/dashboard'], 'browse' => ['/auctions'], 'my lots' => ['/seller/auctions'], 'listing form' => ['/seller/auctions/create'], 'profile' => ['/user/profile']];
    }

    /** @return array<string, array{0: string}> */
    public static function publicPages(): array
    {
        return ['welcome' => ['/'], 'login' => ['/login'], 'terms' => ['/terms-of-service'], 'unknown page' => ['/no-such-page']];
    }

    // ── Roboto ───────────────────────────────────────────────────────────────

    #[DataProvider('signedInPages')]
    public function test_signed_in_pages_declare_roboto_from_this_site_and_nothing_external(string $url): void
    {
        $html = $this->actingAs(User::factory()->withPersonalTeam()->create())->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*class="dot-roboto"/', $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-roboto.css'), $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-type.css'), $html);
        $this->assertStringContainsString(asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'), $html, 'the one file the first paint needs is preloaded');
        $this->assertStringNotContainsString('archivo', strtolower($html));
        $this->assertStringNotContainsString('big-shoulders', strtolower($html));
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.', 'unpkg.com', 'typekit', 'use.fontawesome'] as $external) {
            $this->assertStringNotContainsString($external, $html);
        }
    }

    #[DataProvider('publicPages')]
    public function test_signed_out_pages_and_error_pages_are_roboto_too_with_no_inline_theme_script(string $url): void
    {
        $response = $this->get($url);
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*class="dot-roboto"/', $html);
        $this->assertStringContainsString('dot-ui/css/dot-roboto.css', $html);
        $this->assertStringContainsString('dot-ui/js/dot-theme-boot.js', $html, 'the theme is applied before first paint by an external file');
        $this->assertStringNotContainsString("localStorage.getItem('dot-theme')", $html, 'no inline theme script');
        $this->assertStringNotContainsString('archivo', strtolower($html));
    }

    public function test_the_stylesheets_name_roboto_and_keep_only_the_mono_as_a_second_face(): void
    {
        $tokens = (string) file_get_contents($this->root('resources/css/tokens.css'));
        $this->assertMatchesRegularExpression('/--f-ui:\s*var\(--dot-font-sans,\s*"Roboto"/', $tokens);
        $this->assertStringContainsString('--f-mono: "Chivo Mono"', $tokens);

        $fonts = (string) file_get_contents($this->root('resources/css/fonts.css'));
        $this->assertSame(1, substr_count($fonts, 'font-family: "Chivo Mono";'), 'the mono is the only face this app still declares');
        $this->assertStringNotContainsString('Archivo', $fonts);

        $css = '';
        foreach (glob($this->root('resources/css/*.css')) as $file) {
            $css .= file_get_contents($file);
        }
        $this->assertStringNotContainsString('Archivo', $css);
        $this->assertStringNotContainsString('font-stretch', $css, 'Roboto has no width axis');
        $this->assertDoesNotMatchRegularExpression('/font-weight:\s*(450|550|650|750|800)\b/', $css, 'only real Roboto weights');
        $this->assertStringNotContainsString('fonts.googleapis', $css);

        $tailwind = (string) file_get_contents($this->root('tailwind.config.js'));
        $this->assertStringContainsString('dot-ui/tailwind/tailwind-preset.cjs', $tailwind, 'font-sans and the type scale come from the shared preset');
    }

    public function test_the_mono_is_used_for_the_clock_lot_numbers_and_references_only(): void
    {
        $users = [];
        foreach (glob($this->root('resources/css/*.css')) as $file) {
            if (preg_match_all('/([^{}\n]+)\{[^}]*font-family:\s*var\(--f-mono\)/', (string) file_get_contents($file), $m)) {
                foreach ($m[1] as $selector) {
                    $users[] = trim($selector);
                }
            }
        }
        $this->assertNotEmpty($users);
        foreach ($users as $selector) {
            $this->assertMatchesRegularExpression('/countdown|lot-no|lot__number|er-code|pay-ref|ops-ref|\bcode\b|kbd|samp|pre|dot-mono/', $selector, "$selector is a data readout");
        }
    }

    public function test_roboto_and_the_mono_are_served_files_and_every_dot_ui_url_resolves(): void
    {
        foreach (['dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2', 'dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2', 'fonts/chivo-mono-var-latin.woff2'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        $this->assertFileDoesNotExist(public_path('dot-ui/css/dot-fonts.css'), 'the legacy Archivo copy is gone');
        $this->assertFileDoesNotExist(public_path('dot-ui/css/dot-switcher-fonts.css'));

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' stays on this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }
    }

    public function test_the_built_css_ships_no_archivo(): void
    {
        $manifest = $this->root('public/build/manifest.json');
        if (! is_file($manifest)) {
            $this->markTestSkipped('assets are not built in this environment');
        }
        foreach (glob($this->root('public/build/assets/*.css')) as $file) {
            $this->assertStringNotContainsString('Archivo', (string) file_get_contents($file), basename($file));
            $this->assertStringNotContainsString('font-stretch', (string) file_get_contents($file), basename($file));
        }
    }

    // ── The shared header ────────────────────────────────────────────────────

    #[DataProvider('signedInPages')]
    public function test_every_signed_in_page_has_the_shared_header_with_search_switcher_bell_account_and_menu(string $url): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $xpath = $this->xpath($this->actingAs($user)->get($url)->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//header[contains(@class,"dh")][@data-dh]')->length, 'one header');
        $this->assertSame(0, $xpath->query('//header[contains(@class,"sh-top")]')->length, 'the old top bar is gone');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//a[contains(@class,"dh-brand")]')->length);
        $this->assertSame(1, $xpath->query('//header[@data-dh]//*[@data-dh-search]//input[@type="search"][@name="q"]')->length, 'the search is in the header');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//button[@data-dh-search-toggle]')->length, 'phones get the search button');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//*[contains(@class,"dsw")]//button[@data-dsw-trigger]')->length, 'the platform switcher');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//*[@data-dh-bell]//button[@aria-controls="nf-panel"]')->length, 'the bell is the shared panel bell');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//*[@data-dh-account]//form[@action="'.route('logout').'"]')->length, 'the account menu logs out');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//button[@data-dh-menu-button][@aria-controls="dh-drawer"]')->length, 'the menu button opens the drawer');
        $this->assertGreaterThanOrEqual(1, $xpath->query('//header[@data-dh]//button[@data-dh-theme-set]')->length, 'the account menu carries the theme choice');
        $this->assertSame(1, $xpath->query('//a[contains(@class,"skip-link")][@href="#main"]')->length, 'the skip link comes first');
        $this->assertSame(1, $xpath->query('//main[@id="main"]')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);
    }

    public function test_the_phone_menu_is_the_shared_drawer_with_every_destination_and_the_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $drawer = '//div[@id="dh-drawer"][@data-dh-drawer]';
        $this->assertSame(1, $xpath->query($drawer.'[@hidden]')->length, 'closed until opened');
        foreach (['dashboard', 'auctions.index', 'buyer.bids', 'buyer.watchlist', 'buyer.won', 'seller.auctions.create', 'seller.auctions.index', 'settlements.index'] as $name) {
            $this->assertGreaterThanOrEqual(1, $xpath->query($drawer.'//a[contains(@class,"dh-dnav__link")][@href="'.route($name).'"]')->length, "the drawer lists $name");
        }
        $this->assertSame(0, $xpath->query($drawer.'//a[@href="'.route('operator.settlement-payments.index').'"]')->length, 'operator pages are not offered to everyone');
        $this->assertSame(1, $xpath->query($drawer.'//form[@action="'.route('logout').'"]')->length, 'log out is in the drawer');
        $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('profile.show').'"]')->length);
        $this->assertSame(1, $xpath->query($drawer.'//a[@href="'.route('teams.show', $user->currentTeam->id).'"]')->length);
        $this->assertSame(0, $xpath->query('//dialog[@id="shell-menu"]')->length, 'the old menu sheet is gone');
        // The bottom bar keeps four destinations and "More", which opens the same drawer.
        $this->assertSame(4, $xpath->query('//nav[contains(@class,"sh-bar")]//a')->length);
        $this->assertSame(1, $xpath->query('//nav[contains(@class,"sh-bar")]//button[@data-dh-menu-button][@aria-controls="dh-drawer"]')->length);
    }

    public function test_an_operator_sees_payment_operations_in_the_rail_and_the_drawer(): void
    {
        $operator = User::factory()->withPersonalTeam()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();
        $this->assertTrue($operator->isPlatformOperator());

        $xpath = $this->xpath($this->actingAs($operator)->get('/dashboard')->assertOk()->getContent());
        $href = route('operator.settlement-payments.index');

        $this->assertSame(1, $xpath->query('//nav[contains(@class,"sh-side")]//a[@href="'.$href.'"]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="dh-drawer"]//a[@href="'.$href.'"]')->length);
    }

    public function test_the_account_menu_lists_every_team_to_switch_to(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = Team::factory()->create(['user_id' => $user->id, 'name' => 'Boerdery Naledi', 'personal_team' => false]);

        $xpath = $this->xpath($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());

        $forms = $xpath->query('//header[@data-dh]//form[@action="'.route('current-team.update').'"]');
        $this->assertSame(2, $forms->length, 'both teams are listed in the account menu');
        $this->assertSame(1, $xpath->query('//header[@data-dh]//form[@action="'.route('current-team.update').'"][input[@name="team_id"][@value="'.$other->id.'"]]//button[contains(.,"Boerdery Naledi")]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="dh-drawer"]//form[@action="'.route('current-team.update').'"][input[@name="team_id"][@value="'.$other->id.'"]]')->length, 'and in the drawer');
    }

    public function test_the_header_markup_has_no_inline_script_or_event_handlers_and_scripts_carry_the_nonce(): void
    {
        $response = $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/auctions')->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, preg_match('/<header class="dh.*?<\/header>/s', $html, $m));
        $this->assertStringNotContainsString('<script', $m[0]);
        $this->assertStringNotContainsString('<style', $m[0]);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $m[0]);
        // The logo carries one custom property for its width (a style attribute the policy already allows); nothing else in the bar is inline-styled.
        $this->assertSame(0, preg_match_all('/ style="(?!--brand-w: \d+px")/', $m[0]));

        foreach (['dot-nav.js', 'dot-search.js', 'dot-switcher.js'] as $script) {
            $this->assertMatchesRegularExpression('#<script src="[^"]*dot-ui/js/'.preg_quote($script, '#').'" nonce="[^"]+" defer>#', $html, $script);
        }
        $this->assertStringContainsString('dot-ui/js/dot-theme-boot.js', $html);
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('http', preg_replace('#https?://[^\s;]*report[^\s;]*#', '', $policy), 'the policy lists no external origin');
    }

    public function test_the_lot_page_keeps_its_bid_panel_and_the_countdown_markup(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create(['title' => 'Walnut Sideboard']);

        $html = $this->actingAs($user)->get(route('auctions.show', $lot))->assertOk()->getContent();

        $this->assertStringContainsString('wire:id', $html, 'the Livewire bid panel still renders');
        $this->assertStringContainsString('data-ds-countdown', $html, 'the real countdown the clock script ticks');
        $this->assertStringContainsString('data-ds-bid-input', $html);
    }

    public function test_the_lot_page_panel_cannot_push_the_page_sideways(): void
    {
        $css = (string) file_get_contents($this->root('resources/css/ds-lot.css'));

        $this->assertMatchesRegularExpression('/\.lot-bid__main\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/', $css, 'the bid panel column can shrink to a 320px screen');
        $this->assertMatchesRegularExpression('/\.lot-bid__tags\s*\{[^}]*flex-wrap:\s*wrap/', $css, 'the Watch button wraps under the chips instead of widening the panel');
        $this->assertMatchesRegularExpression('/\.lot-bid__top\s*\{[^}]*min-width:\s*0/', $css);
    }

    public function test_the_header_follows_the_apps_own_tokens_and_the_rail_sits_under_it(): void
    {
        $css = (string) file_get_contents($this->root('resources/css/shell.css'));

        foreach (['--dh-bg: var(--c-ground)', '--dh-accent: var(--c-action)', '--dh-font: var(--f-ui)', '--topbar-h: var(--dot-header-offset'] as $mapping) {
            $this->assertStringContainsString($mapping, $css);
        }
        $this->assertStringContainsString('top: var(--dot-header-offset)', $css, 'the rail does not slide under the sticky header');
        $this->assertStringNotContainsString('.sh-top', $css);
        $this->assertStringNotContainsString('.sh-search', $css, 'the old left-aligned search rules are gone');
    }
}
