<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Team;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The signed-in shell (R-42): a rail from 64rem up, a top bar everywhere, and a bottom bar plus a menu sheet on phones. These
 * tests pin the markup hooks (landmarks, skip link, one h1, titles, navigation, bell, theme); the layout itself is CSS and is
 * checked by eye and in a browser, not here.
 */
class ResponsiveShellTest extends TestCase
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function shellPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'browse' => ['/auctions'],
            'my auctions' => ['/seller/auctions'],
            'list an auction' => ['/seller/auctions/create'],
            'profile' => ['/user/profile'],
        ];
    }

    #[DataProvider('shellPages')]
    public function test_every_shell_page_has_the_skip_link_landmarks_one_h1_a_title_and_both_navigations(string $url): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        $xpath = $this->xpath($html);

        // Reason for the rewrite (R-42 shell): the old fixed sidebar and off-canvas drawer are replaced by a rail on wide screens and a
        // bottom bar plus a menu sheet on phones. The hooks pinned here are the new ones.
        $skip = $xpath->query('//a[contains(@class,"skip-link")][@href="#main"]');
        $this->assertSame(1, $skip->length, 'a skip link comes first');
        $this->assertSame(1, $xpath->query('//main[@id="main"]')->length, 'one main landmark, the skip link target');
        $this->assertSame(1, $xpath->query('//header[contains(@class,"sh-top")]')->length, 'one top bar');
        $this->assertSame(1, $xpath->query('//h1')->length, 'exactly one h1 per page');
        $this->assertNotSame('', trim($xpath->query('//h1')->item(0)->textContent));

        $title = $xpath->query('//title')->item(0)->textContent;
        $this->assertStringEndsWith('| Dot.Auction', $title, 'every page title names the page first');
        $this->assertNotSame('Dot.Auction', $title);

        $rail = $xpath->query('//nav[contains(@class,"sh-side")][@aria-label="Main"]//a[@href="'.route('auctions.index').'"]');
        $this->assertSame(1, $rail->length, 'the rail lists Browse');
        $bar = $xpath->query('//nav[contains(@class,"sh-bar")]//a[@href="'.route('auctions.index').'"]');
        $this->assertSame(1, $bar->length, 'the phone bar lists Browse');
        $this->assertSame(1, $xpath->query('//nav[contains(@class,"sh-bar")]//a[@href="'.route('seller.auctions.create').'"][contains(@class,"is-accent")]')->length, 'the phone bar has one clear primary action');
        $this->assertSame(1, $xpath->query('//nav[contains(@class,"sh-bar")]//button[@data-dialog-open="shell-menu"]')->length, 'More opens the menu sheet');
        $this->assertSame(1, $xpath->query('//dialog[@id="shell-menu"][@aria-labelledby="shell-menu-title"]')->length);
        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content,"width=device-width")]')->length);

        // Nothing from a CDN, and no icon font.
        foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'material-symbols', 'unpkg.com'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "$gone is not loaded any more");
        }
        $this->assertSame(0, $xpath->query('//*[@id="shell-drawer"]')->length, 'the old drawer is gone');
    }

    public function test_the_rail_and_the_phone_menu_carry_the_account_links_team_switcher_and_log_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//dialog[@id="shell-menu"]//a[@href="'.route('teams.show', $user->currentTeam->id).'"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="shell-menu"]//form[@action="'.route('logout').'"]//button[@type="submit"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="shell-menu"]//button[@data-theme-toggle]')->length);
        // Desktop: the account menu in the top bar holds profile, team and log out.
        $this->assertSame(1, $xpath->query('//header[contains(@class,"sh-top")]//div[@data-ds-dropdown]//form[@action="'.route('logout').'"]')->length);
        $this->assertSame(0, $xpath->query('//div[contains(@class,"shell-legacy-nav")]')->length, 'the hidden legacy Jetstream bar is no longer rendered');
    }

    public function test_the_menu_lists_every_team_to_switch_to_when_the_user_has_several(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = Team::factory()->create(['user_id' => $user->id, 'name' => 'Boerdery Naledi', 'personal_team' => false]);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $forms = $xpath->query('//header[contains(@class,"sh-top")]//form[@action="'.route('current-team.update').'"]');
        $this->assertSame(2, $forms->length, 'the account menu lists both teams');
        $this->assertSame(1, $xpath->query('//header[contains(@class,"sh-top")]//form[@action="'.route('current-team.update').'"][input[@name="team_id"][@value="'.$other->id.'"]]//button[contains(.,"Boerdery Naledi")]')->length);
    }

    public function test_the_notification_bell_and_theme_toggle_are_named_and_reachable_in_the_top_bar(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $xpath = $this->xpath($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());

        $bell = $xpath->query('//header[contains(@class,"sh-top")]//button[contains(@class,"ds-iconbtn")][.//*[contains(@class,"sr-only")][contains(.,"Notifications")]]');
        $this->assertSame(1, $bell->length, 'the bell is named by its own text, so the visible count is part of the name');
        $this->assertSame(0, $xpath->query('//header[contains(@class,"sh-top")]//button[@aria-label="Notifications"]')->length, 'no aria-label that differs from the visible text');
        $this->assertSame(1, $xpath->query('//header[contains(@class,"sh-top")]//button[@data-theme-toggle]')->length);
    }

    public function test_the_theme_choice_is_applied_before_first_paint_and_the_bundle_wires_the_behaviour(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('dot-theme')", $html, 'the head script restores the saved theme');
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);

        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/ds.js');
        foreach (["'Escape'", 'data-theme-toggle', 'data-ds-countdown', 'showModal', 'prefers-reduced-motion'] as $hook) {
            $this->assertStringContainsString($hook, $source, "ds.js handles $hook");
        }
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/ds-core.css').(string) file_get_contents(dirname(__DIR__, 2).'/resources/css/base.css');
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function test_the_dashboard_table_scrolls_inside_its_own_container(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Auction::factory()->create(['seller_id' => $user->id, 'title' => 'Hereford heifers, twelve head']);

        // The seller list no longer scrolls sideways: it is a stacked ds.table that becomes a card per lot (see SellerListingsPageTest).
        foreach (['/dashboard'] as $url) {
            $xpath = $this->xpath($this->actingAs($user)->get($url)->assertOk()->getContent());

            $wrapped = $xpath->query('//div[contains(@class,"table-scroll")]//table[contains(@class,"dot-table")]');
            $this->assertSame(1, $wrapped->length, "$url: the auctions table sits in a .table-scroll wrapper");
            $this->assertSame(0, $xpath->query('//table[not(ancestor::div[contains(@class,"table-scroll")])]')->length, "$url: no bare table");
            $region = $xpath->query('//div[contains(@class,"table-scroll")]')->item(0);
            $this->assertSame('0', $region->getAttribute('tabindex'), 'A scroll region must be keyboard-focusable');
        }
    }

    public function test_browse_filters_and_the_dashboard_grids_use_the_stacking_classes(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $browse = $this->xpath($this->actingAs($user)->get('/auctions')->assertOk()->getContent());
        $this->assertSame(1, $browse->query('//form[contains(@class,"dot-filter-form")]')->length);
        $this->assertSame(1, $browse->query('//div[contains(@class,"dot-page")]')->length);

        $dashboard = $this->xpath($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());
        $this->assertSame(1, $dashboard->query('//div[contains(@class,"dot-kpi-grid")]')->length);
        $this->assertSame(5, $dashboard->query('//div[contains(@class,"dot-kpi-grid")]/div[contains(@class,"dot-kpi")]')->length);
        $this->assertSame(1, $dashboard->query('//div[contains(@class,"dot-module-grid")]')->length);
    }

    public function test_the_auction_page_stat_grid_and_two_column_layout_stack_on_phones(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $auction = Auction::factory()->create(['title' => 'Massey Ferguson 290 tractor']);

        $xpath = $this->xpath($this->actingAs($user)->get(route('auctions.show', $auction))->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//div[contains(@class,"dot-two-col")]')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"dot-stat-grid")]')->length);
    }
}
