<?php

namespace Tests\Feature\Security;

use App\Livewire\Auctions\BidPanel;
use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Livewire\Auctions\WatchlistButton;
use App\Livewire\Buyer\WatchToggle;
use App\Livewire\NotificationBell;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a hostile browser can do to a Livewire component by editing the requests it sends: set any public property it is
 * allowed to, call any public method with any arguments. Each case sends the tampered update through Livewire::test (the
 * same code path as a real /livewire/update request) and checks that nothing a person should not be able to do happened.
 */
class LivewireTamperTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name = 'Tamper Person'): User
    {
        return User::factory()->withPersonalTeam()->create(['name' => $name]);
    }

    private function lot(User $seller, array $overrides = []): Auction
    {
        return Auction::factory()->create(array_merge(['seller_id' => $seller->id], $overrides));
    }

    // ---- BidPanel ----------------------------------------------------------------------------------------------------

    public function test_the_bid_panel_will_not_be_re_pointed_at_another_auction(): void
    {
        $seller = $this->person();
        $mine = $this->lot($seller);
        $draft = $this->lot($this->person(), ['status' => 'draft', 'title' => 'Somebody elses draft']);
        $bidder = $this->person();
        $this->actingAs($bidder);

        foreach (['auction.id', 'auction.status', 'auction.current_price', 'auction.seller_id', 'auction.reserve_price', 'auction.title'] as $path) {
            $threw = false;
            try {
                Livewire::test(BidPanel::class, ['auction' => $mine])->set($path, $path === 'auction.id' ? $draft->id : 1);
            } catch (\Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, "BidPanel accepted a client write to {$path}");
        }

        // Replacing the whole model with a bare id (or anything else) never swaps the auction the panel works on.
        foreach ([$draft->id, ['id' => $draft->id], (string) $draft->id] as $value) {
            try {
                $panel = Livewire::test(BidPanel::class, ['auction' => $mine])->set('auction', $value);
                $this->assertSame($mine->id, $panel->get('auction.id'));
                $this->assertStringNotContainsString('Somebody elses draft', $panel->html());
            } catch (\Throwable) {
                $this->assertTrue(true); // refused outright is fine too
            }
        }
        $this->assertSame(0, Bid::where('auction_id', $draft->id)->count());
    }

    public function test_the_bid_panel_snapshot_reveals_nothing_a_bidder_may_not_see(): void
    {
        $seller = $this->person();
        $lot = $this->lot($seller, ['reserve_price' => '777.77', 'starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '10.00']);
        $other = $this->person('Rival Bidder');
        Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $other->id, 'amount' => '110.00', 'is_winning' => true]);
        $lot->forceFill(['current_price' => '110.00'])->save();

        $this->actingAs($this->person());
        $snapshot = Livewire::test(BidPanel::class, ['auction' => $lot])->call('snapshot')->effects['returns'][0] ?? null;

        $this->assertIsArray($snapshot);
        $json = json_encode($snapshot);
        foreach (['777', 'reserve', 'Rival', 'bidder_id', 'seller', 'email'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json, "the snapshot leaks '{$needle}'");
        }
        $this->assertFalse($snapshot['leading_bidder_is_me']);
        $this->assertEqualsCanonicalizing([
            'auction_id', 'status', 'current_price', 'current_price_cents', 'minimum_bid', 'bid_count', 'starts_at', 'ends_at',
            'seconds_remaining', 'leading_bidder_is_me', 'server_now', 'server_now_ms', 'poll_seconds', 'realtime',
        ], array_keys($snapshot), 'the snapshot contract changed; review what it now exposes');
    }

    public function test_a_snapshot_of_somebody_elses_draft_is_not_available_through_the_service_either(): void
    {
        $draft = $this->lot($this->person(), ['status' => 'draft']);

        $this->actingAs($this->person());
        $snap = app(\App\Services\Auctions\AuctionSnapshot::class)->for($draft->id, auth()->user());

        $this->assertSame('missing', $snap['status'], 'a stranger can read a draft through the snapshot service');
        $this->assertArrayNotHasKey('current_price', $snap);

        $this->actingAs($draft->seller);
        $this->assertSame('draft', app(\App\Services\Auctions\AuctionSnapshot::class)->for($draft->id, $draft->seller)['status']);
    }

    public function test_hostile_values_in_the_bid_panels_own_fields_cannot_break_it_or_place_a_bid(): void
    {
        $lot = $this->lot($this->person());
        $this->actingAs($bidder = $this->person());

        foreach ([
            str_repeat('9', 5000), '1e400', '-5', '0', '   ', '<script>alert(1)</script>', "10\0", '1,5', 'NaN', 'INF', '0x1A', '٣٠٠', '300.999',
        ] as $amount) {
            Livewire::test(BidPanel::class, ['auction' => $lot])->set('bidAmount', $amount)->call('placeBid');
        }
        $this->assertSame(0, Bid::where('auction_id', $lot->id)->count(), 'a hostile amount was accepted as a bid');

        // A token the browser invents: huge, or full of cache-key characters. It must not 500 and must not skip the rules.
        foreach ([str_repeat('t', 10000), "a:b\n c\0d", '../../x', ''] as $token) {
            Livewire::test(BidPanel::class, ['auction' => $lot])->set('submissionToken', $token)->set('bidAmount', '1')->call('placeBid')->assertSet('success', null);
        }
        $this->assertSame(0, Bid::where('auction_id', $lot->id)->count());
    }

    public function test_a_client_cannot_skip_the_rules_by_editing_the_panels_status_flags(): void
    {
        $seller = $this->person();
        $lot = $this->lot($seller, ['ends_at' => now()->subMinute()]); // closed
        $this->actingAs($this->person());

        Livewire::test(BidPanel::class, ['auction' => $lot])
            ->set('placing', false)->set('purchased', false)->set('error', null)->set('success', 'Bid placed successfully!')
            ->set('bidAmount', '500')->call('placeBid')
            ->assertSet('success', null);

        $this->assertSame(0, Bid::where('auction_id', $lot->id)->count());

        // The seller of the lot cannot bid or Buy Now on it whatever the page says.
        $open = $this->lot($seller, ['buy_now_price' => '900.00']);
        $this->actingAs($seller);
        Livewire::test(BidPanel::class, ['auction' => $open])->set('bidAmount', '300')->call('placeBid')->call('buyNow');
        $this->assertSame(0, Bid::where('auction_id', $open->id)->count());
    }

    public function test_text_a_client_writes_into_the_panels_messages_is_escaped_not_rendered(): void
    {
        $lot = $this->lot($this->person());
        $this->actingAs($this->person());

        $html = Livewire::test(BidPanel::class, ['auction' => $lot])
            ->set('error', '<img src=x onerror=alert(1)>')->set('success', '<script>alert(2)</script>')
            ->html();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
    }

    // ---- Watch controls -----------------------------------------------------------------------------------------------

    public function test_the_watch_toggle_has_locked_fields_and_respects_who_may_see_a_lot(): void
    {
        $seller = $this->person();
        $lot = $this->lot($seller);
        $draft = $this->lot($seller, ['status' => 'draft']);
        $viewer = $this->person();
        $this->actingAs($viewer);

        foreach (['auctionId' => $draft->id, 'title' => 'x'] as $property => $value) {
            $threw = false;
            try {
                Livewire::test(WatchToggle::class, ['auctionId' => $lot->id, 'title' => 'Lot', 'watching' => false])->set($property, $value);
            } catch (\Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, "WatchToggle::\${$property} can be written by a browser");
        }

        // The seller's draft cannot be watched by a stranger even with the component mounted on it directly.
        Livewire::test(WatchToggle::class, ['auctionId' => $draft->id, 'title' => 'd', 'watching' => false])->call('toggle')->assertNotFound();
        $this->assertSame(0, Watchlist::withoutGlobalScopes()->where('auction_id', $draft->id)->count());

        // And the seller cannot watch their own lot.
        $this->actingAs($seller);
        Livewire::test(WatchToggle::class, ['auctionId' => $lot->id, 'title' => 'Lot', 'watching' => false])->call('toggle')->assertForbidden();
    }

    public function test_the_old_watch_button_cannot_be_re_pointed_at_a_draft(): void
    {
        $draft = $this->lot($this->person(), ['status' => 'draft']);
        $lot = $this->lot($this->person());
        $this->actingAs($this->person());

        $threw = false;
        try {
            Livewire::test(WatchlistButton::class, ['auction' => $lot])->set('auction.id', $draft->id);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertSame(0, Watchlist::withoutGlobalScopes()->where('auction_id', $draft->id)->count());
    }

    // ---- Notification bell --------------------------------------------------------------------------------------------

    public function test_the_bell_cannot_read_or_open_somebody_elses_notification(): void
    {
        $owner = $this->person();
        $thief = $this->person();
        $note = DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => ['url' => '/auctions', 'message' => 'private']]);

        $this->actingAs($thief);
        $bell = Livewire::test(NotificationBell::class);

        $threw = false;
        try {
            $bell->set('userId', $owner->id);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the bell user id can be written by a browser');

        $bell->call('openNotification', $note->id)->call('markAsRead', $note->id)->assertNoRedirect();
        $this->assertNull($note->fresh()->read_at);
        $this->assertStringNotContainsString('private', $bell->html());

        // markAllAsRead only touches the caller's own.
        $bell->call('markAllAsRead');
        $this->assertNull($note->fresh()->read_at);
    }

    public function test_the_bell_never_follows_an_off_site_address(): void
    {
        $user = $this->person();
        $this->actingAs($user);

        foreach (['javascript:alert(1)', '//evil.example', 'https://evil.example/x', '/\\evil.example'] as $url) {
            $note = DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => ['url' => $url]]);
            Livewire::test(NotificationBell::class)->call('openNotification', $note->id)->assertNoRedirect();
        }
    }

    // ---- Reserve decision ---------------------------------------------------------------------------------------------

    public function test_only_the_lots_seller_can_decide_its_reserve_and_the_proposal_cannot_be_swapped(): void
    {
        $seller = $this->person();
        $rival = $this->person();
        $make = function (User $s) {
            $lot = $this->lot($s, ['status' => 'pending_review', 'reserve_price' => '900.00', 'ends_at' => now()->subHour(), 'starts_at' => now()->subDays(2)]);
            $bid = Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $this->person()->id, 'amount' => '300.00', 'is_winning' => true]);

            return ReserveNotMetProposal::forceCreate(['auction_id' => $lot->id, 'bid_id' => $bid->id, 'reserve_price' => '900.00', 'status' => 'pending']);
        };
        $victims = $make($seller);
        $mine = $make($rival);

        $this->actingAs($rival);
        Livewire::test(ReserveDecisionPanel::class, ['proposal' => $victims])->call('accept')->assertForbidden();
        Livewire::test(ReserveDecisionPanel::class, ['proposal' => $victims])->call('reject')->assertForbidden();

        $threw = false;
        try {
            Livewire::test(ReserveDecisionPanel::class, ['proposal' => $mine])->set('proposal.id', $victims->id)->call('accept');
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the proposal id can be swapped by a browser');

        $this->assertSame('pending', $victims->fresh()->status);
        $this->assertSame('pending_review', $victims->auction->fresh()->status);
    }

    // ---- Profile (Jetstream) ------------------------------------------------------------------------------------------

    public function test_the_profile_form_cannot_be_used_to_grant_rights_or_move_the_account_to_another_email(): void
    {
        $user = $this->person('Profile Person');
        $email = $user->email;
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'Renamed', 'email' => 'victim@elsewhere.test', 'is_platform_operator' => true, 'current_team_id' => 999, 'password' => 'x', 'email_verified_at' => null])
            ->call('updateProfileInformation');

        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertFalse($fresh->isPlatformOperator(), 'the profile form granted operator rights');
        $this->assertNotSame(999, $fresh->current_team_id);
        $this->assertSame($email, $fresh->email, 'the e-mail address belongs to InfoDot; matching by e-mail would let this account be taken over by the next person to sign in with that address');
    }
}
