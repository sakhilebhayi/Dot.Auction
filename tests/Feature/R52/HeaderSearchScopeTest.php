<?php

namespace Tests\Feature\R52;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Team;
use App\Models\User;
use App\Services\Auctions\LotBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R-52: the header search answers one question, "which lots match this word", and may only ever answer with what the person
 * is allowed to see. In this app that is decided by AuctionPolicy::view(): a lot is public the moment it is published
 * (open, scheduled, awaiting a seller decision, ended, cancelled); a draft is private to its seller and is not a search result
 * even for the seller (drafts live under My lots). Nothing else is searched: no descriptions, reserves, seller details, bids,
 * settlements or another business's data.
 */
class HeaderSearchScopeTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $title, string $status = 'active', array $extra = []): Auction
    {
        return Auction::forceCreate($extra + [
            'seller_id' => User::factory()->create()->id, 'title' => $title, 'description' => 'A lot.', 'starting_price' => 100, 'current_price' => 100,
            'bid_increment' => 10, 'status' => $status, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
        ]);
    }

    private function companyUser(string $teamName): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $teamName, 'personal_team' => false]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function search(User $viewer, string $q, array $more = []): string
    {
        return $this->actingAs($viewer)->get(route('auctions.index', ['q' => $q] + $more))->assertOk()->getContent();
    }

    public function test_a_signed_out_visitor_gets_no_results_and_no_search(): void
    {
        $this->lot('Brass Telescope');

        $this->get(route('auctions.index', ['q' => 'brass']))->assertRedirect(route('login'));
    }

    public function test_only_published_lots_are_found_and_drafts_never_are(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->lot('Brass Open Lot');
        $this->lot('Brass Scheduled Lot', 'active', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);
        $this->lot('Brass Ended Lot', 'ended', ['ends_at' => now()->subHour()]);
        $this->lot('Brass Waiting Lot', 'pending_review', ['ends_at' => now()->subHour()]);
        $this->lot('Brass Cancelled Lot', 'cancelled');
        $this->lot('Brass Draft Lot', 'draft');

        $html = $this->search($viewer, 'brass');

        foreach (['Brass Open Lot', 'Brass Scheduled Lot', 'Brass Ended Lot', 'Brass Waiting Lot', 'Brass Cancelled Lot'] as $title) {
            $this->assertStringContainsString($title, $html, "$title is published, so it is public");
        }
        $this->assertStringNotContainsString('Brass Draft Lot', $html);
    }

    public function test_a_sellers_own_draft_is_not_a_search_result_either(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $this->lot('Walnut Draft Cabinet', 'draft', ['seller_id' => $seller->id]);
        $this->lot('Walnut Live Cabinet', 'active', ['seller_id' => $seller->id]);

        $html = $this->search($seller, 'walnut');

        $this->assertStringContainsString('Walnut Live Cabinet', $html);
        $this->assertStringNotContainsString('Walnut Draft Cabinet', $html, 'drafts are managed under My lots, not found through the marketplace search');
    }

    public function test_a_status_the_app_does_not_know_is_not_public(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->lot('Quartz Hidden Lot', 'under_moderation');
        $this->lot('Quartz Open Lot');

        $html = $this->search($viewer, 'quartz');

        $this->assertStringContainsString('Quartz Open Lot', $html);
        $this->assertStringNotContainsString('Quartz Hidden Lot', $html, 'the allow-list fails closed: a new internal status stays private until it is made public on purpose');
        $this->assertSame(LotBrowser::PUBLIC_STATUSES, array_values(array_intersect(LotBrowser::PUBLIC_STATUSES, ['active', 'pending_review', 'ended', 'cancelled'])));
        $this->assertNotContains('draft', LotBrowser::PUBLIC_STATUSES);
    }

    public function test_the_search_reads_the_title_only(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $seller = User::factory()->create(['name' => 'Zephyrine Seller', 'email' => 'zephyrine.seller@example.test']);
        $this->lot('Plain Lamp', 'active', ['seller_id' => $seller->id, 'description' => 'Contains the word marzipan.', 'reserve_price' => 777]);

        foreach (['marzipan', 'Zephyrine', 'zephyrine.seller', '777', 'example.test'] as $needle) {
            $this->assertStringNotContainsString('Plain Lamp', $this->search($viewer, $needle), "'$needle' is not in the title, so it finds nothing");
        }
        $this->assertStringContainsString('Plain Lamp', $this->search($viewer, 'plain lamp'));
    }

    public function test_results_never_carry_the_reserve_the_sellers_contact_or_other_peoples_bids(): void
    {
        $bidder = User::factory()->withPersonalTeam()->create(['email' => 'private.bidder@example.test']);
        $viewer = User::factory()->withPersonalTeam()->create();
        $seller = User::factory()->create(['email' => 'private.seller@example.test']);
        $lot = $this->lot('Ledger Clock', 'active', ['seller_id' => $seller->id, 'reserve_price' => 4321]);
        Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $bidder->id, 'amount' => 110, 'is_winning' => true]);

        $html = $this->search($viewer, 'ledger');

        $this->assertStringContainsString('Ledger Clock', $html);
        foreach (['4321', '4,321', 'private.seller@example.test', 'private.bidder@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_two_businesses_search_the_same_public_lots_and_neither_sees_the_others_drafts_or_data(): void
    {
        $acme = $this->companyUser('Acme Ltd');
        $globex = $this->companyUser('Globex Ltd');
        $this->lot('Copper Public Kettle');
        $this->lot('Copper Acme Draft', 'draft', ['seller_id' => $acme->id]);
        $this->lot('Copper Globex Draft', 'draft', ['seller_id' => $globex->id]);

        $acmeHtml = $this->search($acme, 'copper');
        $globexHtml = $this->search($globex, 'copper');

        foreach ([$acmeHtml, $globexHtml] as $html) {
            $this->assertStringContainsString('Copper Public Kettle', $html, 'a published lot is public to every signed-in business');
            $this->assertStringNotContainsString('Copper Acme Draft', $html);
            $this->assertStringNotContainsString('Copper Globex Draft', $html);
        }
        $this->assertStringNotContainsString('Globex Ltd', $acmeHtml, "one business's name is not in the other's search page");
        $this->assertStringNotContainsString('Acme Ltd', $globexHtml);
    }

    public function test_the_tab_counts_and_category_chips_for_a_search_never_count_drafts(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->lot('Slate Open Lot');
        $this->lot('Slate Draft One', 'draft');
        $this->lot('Slate Draft Two', 'draft');

        $counts = (new LotBrowser)->counts('slate', null);

        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['live']);
        $this->actingAs($viewer)->get(route('auctions.index', ['q' => 'slate']))->assertOk()->assertViewHas('tally', fn ($t) => $t['all'] === 1);
    }

    public function test_wildcards_and_quotes_in_the_query_are_literal_and_a_long_query_is_capped(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->lot('Fifty percent off lamp');
        $this->lot('Fifty% sale lamp');

        $html = $this->search($viewer, '%');
        $this->assertStringContainsString('Fifty% sale lamp', $html);
        $this->assertStringNotContainsString('Fifty percent off lamp', $html, '% matches only a real percent sign');
        $this->assertStringNotContainsString('Fifty percent off lamp', $this->search($viewer, '_'));
        $this->actingAs($viewer)->get(route('auctions.index', ['q' => "' OR 1=1 --"]))->assertOk();
        $this->actingAs($viewer)->get(route('auctions.index', ['q' => str_repeat('a', 5000)]))->assertOk();
        $this->actingAs($viewer)->get(route('auctions.index', ['q' => ['x' => 'y']]))->assertOk();
    }

    public function test_the_header_search_is_the_one_form_to_browse_on_every_signed_in_page_type(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();
        $lot = $this->lot('Header Lamp');

        $pages = [
            route('dashboard'), route('auctions.index'), route('auctions.show', $lot), route('seller.auctions.index'), route('seller.auctions.create'),
            route('buyer.bids'), route('buyer.watchlist'), route('buyer.won'), route('settlements.index'), route('notifications.index'),
            route('profile.show'), route('teams.show', $viewer->currentTeam->id),
        ];
        foreach ($pages as $url) {
            $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match('/<form role="search" method="get"\s+action="([^"]+)"\s+id="shell-search-form"/', $html, $m), "$url has the header search");
            $this->assertSame(route('auctions.index'), html_entity_decode($m[1]), "$url: the search goes to Browse");
            $this->assertStringContainsString('data-dh-search', $html, "$url: inside the shared header");
            $this->assertStringContainsString('data-dh-search-toggle', $html, "$url: the phone search button");
        }
    }
}
