<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * The landing block at the top of Home (resources/views/dashboard/_landing.blade.php): what a person meets when they
 * arrive from InfoDot. One dominant action per role, the role read from the person's own rows, the real logo, a welcome
 * from real data, and no figure that is not a seeded record.
 */
class LandingExperienceTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    /** The two logo files as committed. If either hash changes, the artwork was touched. */
    private const LOGO_SHA256 = [
        'images/logo.png' => 'fdccdf73243321cd0d6df5b3f391b51e6470c8bc0dce29b022a072308fcc7ec6',
        'images/logo-light.png' => '62cd2b850978e6289f2afaa878691eca48095d361e78edfeb0cf41fde9d9a553',
    ];

    /** Every platform key the ecosystem still has. */
    private const PLATFORM_KEYS = [
        'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance',
        'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot',
    ];

    public function test_a_new_member_lands_as_a_buyer_with_one_action_that_opens_and_an_honest_empty_state(): void
    {
        $user = $this->person('Nomsa Zulu');

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $landing = $this->landing($response);

        $this->assertSame('buyer', $this->role($response));
        $this->assertSame(route('auctions.index'), $this->primaryAction($response));
        $this->actingAs($user)->get($this->primaryAction($response))->assertOk();

        $this->assertStringContainsString('Welcome to the sale room, Nomsa', $landing);
        $this->assertStringContainsString('No lot is open for bids on Dot.Auction right now.', $landing);
        $this->assertStringContainsString('Nothing is live right now', $landing);
        // Nothing is seeded, so the block may not show a single figure: no count, no price, no clock.
        $this->assertDoesNotMatchRegularExpression('/\d/', strip_tags($landing));
        $this->assertStringNotContainsString('data-ds-countdown', $response->getContent());
        // The other path is still there, and it is not dressed as the main one.
        $response->assertSee('href="'.route('seller.auctions.create').'"', false);
    }

    public function test_a_buyer_sees_the_real_open_count_and_the_lot_that_closes_next(): void
    {
        $buyer = $this->person('Lerato Dlamini');
        $later = $this->lot(['title' => 'Oak Desk', 'ends_at' => now()->addDays(3)]);
        $next = $this->lot(['title' => 'Steel Shelving', 'ends_at' => now()->addHours(5), 'starting_price' => '150.00', 'current_price' => '150.00']);
        $this->lot(['title' => 'Not Open Yet', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4)]);
        $this->lot(['title' => 'Already Closed', 'status' => 'ended', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $this->bid($next, $this->person('Rival'), '480.00');
        $this->bid($later, $buyer, '220.00');

        $response = $this->actingAs($buyer)->get('/dashboard')->assertOk();
        $landing = $this->landing($response);

        $this->assertSame('buyer', $this->role($response));
        $this->assertSame(route('auctions.index'), $this->primaryAction($response));
        $this->actingAs($buyer)->get($this->primaryAction($response))->assertOk();

        // Two lots are open (the scheduled one and the closed one are not), and the one that closes first is shown.
        $this->assertStringContainsString('2 lots are open for bids on Dot.Auction right now.', $landing);
        $this->assertStringContainsString('Closing next on the floor', $landing);
        $this->assertStringContainsString('Steel Shelving', $landing);
        $this->assertStringNotContainsString('Oak Desk', $landing);
        $this->assertStringContainsString('1 bid', $landing);
        $this->assertStringContainsString('R480.00', preg_replace('/\s+/', '', strip_tags($landing)));
        // The clock counts to the stored closing time, to the millisecond.
        $this->assertStringContainsString('data-target="'.$next->fresh()->ends_at->getTimestampMs().'"', $response->getContent());
    }

    public function test_a_seller_lands_on_listing_a_lot_with_their_own_count_and_their_own_next_lot(): void
    {
        $seller = $this->person('Thandi Mokoena');
        $this->lot(['title' => 'Wool Rug', 'ends_at' => now()->addHours(2), 'starting_price' => '900.00', 'current_price' => '900.00'], $seller);
        $this->lot(['title' => 'Stand Mixer', 'ends_at' => now()->addDays(2)], $seller);
        $this->lot(['title' => 'Still A Draft', 'status' => 'draft'], $seller);
        $this->lot(['title' => 'Somebody Else', 'ends_at' => now()->addMinutes(30)]);

        $response = $this->actingAs($seller)->get('/dashboard')->assertOk();
        $landing = $this->landing($response);

        $this->assertSame('seller', $this->role($response));
        $this->assertSame(route('seller.auctions.create'), $this->primaryAction($response));
        $this->actingAs($seller)->get($this->primaryAction($response))->assertOk();

        $this->assertStringContainsString('Welcome to the sale room, Thandi', $landing);
        $this->assertStringContainsString('You have 2 lots open for bids on Dot.Auction.', $landing);
        $this->assertStringContainsString('Your next lot to close', $landing);
        $this->assertStringContainsString('Wool Rug', $landing);
        $this->assertStringContainsString('0 bids', $landing);
        $this->assertStringContainsString('Starting bid', $landing);
        $this->assertStringContainsString('R900.00', preg_replace('/\s+/', '', strip_tags($landing)));
        $this->assertStringNotContainsString('Somebody Else', $landing);
        // Browsing stays reachable, as a link beside the gold button.
        $this->assertStringContainsString('href="'.route('auctions.index').'"', $response->getContent());
    }

    public function test_a_seller_with_nothing_open_is_told_so_from_their_own_counts(): void
    {
        $seller = $this->person('Pieter Merwe');
        $this->lot(['status' => 'draft'], $seller);
        $this->lot(['status' => 'draft'], $seller);
        $this->lot(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)], $seller);

        $response = $this->actingAs($seller)->get('/dashboard')->assertOk();
        $landing = $this->landing($response);

        $this->assertSame('seller', $this->role($response));
        $this->assertStringContainsString('You have no lot open for bids on Dot.Auction right now.', $landing);
        $this->assertStringContainsString('None of your lots is open for bids', $landing);
        $this->assertStringContainsString('You have 1 scheduled, 2 drafts not published.', $landing);
        $this->assertStringContainsString('href="'.route('seller.auctions.index').'"', $landing);
        $this->assertStringNotContainsString('data-ds-countdown', $landing);
    }

    public function test_a_person_who_buys_more_than_they_sell_lands_as_a_buyer(): void
    {
        $user = $this->person('Sipho Nkosi');
        $this->lot(['status' => 'draft'], $user);
        $this->bid($this->lot(), $user, '210.00');
        $this->bid($this->lot(), $user, '220.00');

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame('buyer', $this->role($response));
        $this->assertSame(route('auctions.index'), $this->primaryAction($response));
    }

    public function test_the_welcome_names_the_company_and_never_a_personal_team(): void
    {
        $user = $this->person('Aisha Patel');
        $personal = $this->landing($this->actingAs($user)->get('/dashboard')->assertOk());
        $this->assertStringNotContainsString('You are here for', $personal);
        $this->assertStringNotContainsString($user->currentTeam->name, $personal);

        $company = Team::forceCreate(['user_id' => $user->id, 'name' => 'Patel & Sons Hardware', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $company->id])->save();

        $named = $this->landing($this->actingAs($user->fresh())->get('/dashboard')->assertOk());
        $this->assertStringContainsString('You are here for Patel & Sons Hardware, and no lot is open for bids', html_entity_decode($named));
    }

    public function test_the_landing_shows_the_original_logo_files_and_the_way_back_to_infodot(): void
    {
        foreach (self::LOGO_SHA256 as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), $file.' is not the original artwork.');
        }

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.co.za/']);
        $response = $this->actingAs($this->person())->get('/dashboard')->assertOk();
        $landing = $this->landing($response);

        foreach (array_keys(self::LOGO_SHA256) as $file) {
            $this->assertStringContainsString('src="'.asset($file).'"', $landing);
        }
        $this->assertMatchesRegularExpression('#<a href="https://infodot\.co\.za/home">InfoDot</a>#', $landing);
        $this->assertMatchesRegularExpression('#<a href="https://infodot\.co\.za/platforms">Platforms</a>#', $landing);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_the_platform_list_holds_only_platforms_that_still_exist(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), self::PLATFORM_KEYS)));
        $this->assertSame('Dot.Auction', config('ecosystem.platforms.auction.name'));
    }

    /** The landing block's markup. */
    private function landing(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('#<header class="ld".*?</header>#s', $response->getContent(), $m), 'The landing block is missing.');

        return $m[0];
    }

    private function role(TestResponse $response): string
    {
        preg_match('#<header class="ld" data-landing="([a-z]+)"#', $response->getContent(), $m);

        return $m[1] ?? '';
    }

    /** The href of the page's one primary action; fails unless exactly one element on the whole page carries the marker. */
    private function primaryAction(TestResponse $response): string
    {
        $html = $response->getContent();
        $this->assertSame(1, preg_match_all('#<[a-z]+\b[^>]*\sdata-primary-action[\s=>]#', $html), 'Home must have exactly one primary action.');
        $this->assertSame(1, preg_match('#<a href="([^"]+)"[^>]*data-primary-action#', $html, $m), 'The primary action is not a link.');

        return html_entity_decode($m[1]);
    }
}
