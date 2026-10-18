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
 * The phone shell: below 860px the sidebar is an off-canvas drawer opened from a labelled Menu
 * button. These tests pin the markup hooks (see the "Responsive shell" section of the README);
 * the layout itself is CSS and is checked by eye, not here.
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
    public function test_every_shell_page_has_the_labelled_menu_button_drawer_and_backdrop(string $url): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $button = $xpath->query('//header[contains(@class,"topbar")]//button[@id="shell-menu-button"]');
        $this->assertSame(1, $button->length, 'Menu button must live in the top bar');
        $this->assertSame('false', $button->item(0)->getAttribute('aria-expanded'));
        $this->assertSame('shell-drawer', $button->item(0)->getAttribute('aria-controls'));
        $label = '';
        foreach ($xpath->query('.//text()[not(ancestor::span[@aria-hidden="true"])]', $button->item(0)) as $text) {
            $label .= $text->textContent;
        }
        $this->assertSame('Menu', trim(preg_replace('/\s+/', ' ', $label) ?? ''), 'The button carries a visible text label, not just an icon');
        $this->assertSame('', $button->item(0)->getAttribute('aria-label'), 'The visible text is the accessible name');

        $drawer = $xpath->query('//aside[@id="shell-drawer"]');
        $this->assertSame(1, $drawer->length);
        $this->assertNotSame('', $drawer->item(0)->getAttribute('aria-label'));
        $this->assertSame(1, $xpath->query('//aside[@id="shell-drawer"]//nav//a[@href="'.route('auctions.index').'"]')->length, 'The nav lives inside the drawer');
        $this->assertSame(1, $xpath->query('//aside[@id="shell-drawer"]//button[@id="shell-close"][@aria-label="Close menu"]')->length);

        $this->assertSame(1, $xpath->query('//div[@id="shell-backdrop"][contains(@class,"shell-backdrop")]')->length);
        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content,"width=device-width")]')->length);
    }

    public function test_the_phone_drawer_carries_the_team_switcher_and_log_out_that_the_legacy_bar_hides(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//aside[@id="shell-drawer"]//div[contains(@class,"shell-account")]//a[@href="'.route('teams.show', $user->currentTeam->id).'"]')->length);
        $this->assertSame(1, $xpath->query('//aside[@id="shell-drawer"]//form[@action="'.route('logout').'"]//button[@type="submit"]')->length);
        // The legacy Jetstream bar is wrapped so the phone layout can hide it.
        $this->assertSame(1, $xpath->query('//div[contains(@class,"shell-legacy-nav")]/nav')->length);
    }

    public function test_the_drawer_lists_every_team_to_switch_to_when_the_user_has_several(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = Team::factory()->create(['user_id' => $user->id, 'name' => 'Boerdery Naledi', 'personal_team' => false]);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $forms = $xpath->query('//aside[@id="shell-drawer"]//form[@action="'.route('current-team.update').'"]');
        $this->assertSame(2, $forms->length);
        $this->assertSame(1, $xpath->query('//aside[@id="shell-drawer"]//form[@action="'.route('current-team.update').'"][input[@name="team_id"][@value="'.$other->id.'"]]//button[contains(.,"Boerdery Naledi")]')->length);
    }

    public function test_the_notification_bell_is_labelled_and_reachable_in_the_top_bar(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $xpath = $this->xpath($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//header[contains(@class,"topbar")]//button[@aria-label="Notifications"]')->length);
        $this->assertSame(1, $xpath->query('//header[contains(@class,"topbar")]//button[@aria-label="Toggle dark mode"]')->length);
    }

    public function test_the_shell_script_wires_escape_backdrop_and_the_breakpoint(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString("getElementById('shell-menu-button')", $html);
        $this->assertStringContainsString("'Escape'", $html);
        $this->assertStringContainsString('(min-width: 860px)', $html);
        $this->assertStringContainsString('max-width: 859.98px', $html);
        $this->assertStringContainsString('prefers-reduced-motion', $html);
    }

    public function test_the_dashboard_and_seller_tables_scroll_inside_their_own_container(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Auction::factory()->create(['seller_id' => $user->id, 'title' => 'Hereford heifers, twelve head']);

        foreach (['/dashboard', '/seller/auctions'] as $url) {
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
