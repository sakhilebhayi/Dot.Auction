<?php

namespace Tests\Feature\Security;

use App\Livewire\NotificationBell;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionItem;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\Team;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\AuctionEndingSoonNotification;
use App\Notifications\AuctionSoldNotification;
use App\Notifications\AuctionWonNotification;
use App\Notifications\OutbidNotification;
use App\Notifications\ReserveNotMetNotification;
use App\Notifications\SettlementCancelledNotification;
use App\Notifications\SettlementOverdueNotification;
use App\Notifications\SettlementPaidNotification;
use App\Notifications\SettlementPaymentOpenedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every text a person can type, planted with markup and a script, then every page that can show it, opened as every kind
 * of viewer. Blade escapes by default; this proves nobody switched it off, that notification texts built from lot
 * titles stay text, and that a reflected search term does too.
 */
class OutputSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const XSS = '"><img src=x onerror=alert(1)><script>alert(1)</script>';

    private const NEEDLES = ['<img src=x', '<script>alert(1)', 'onerror=alert(1)>', '"><img src=x'];

    /** @var array<string, User> */
    private array $who = [];

    private Auction $lot;

    private AuctionSettlement $sale;

    private function plant(): void
    {
        $x = self::XSS;
        $this->who['seller'] = User::factory()->withPersonalTeam()->create(['name' => 'S '.$x]);
        $this->who['bidder'] = User::factory()->withPersonalTeam()->create(['name' => 'B '.$x]);
        $this->who['winner'] = User::factory()->withPersonalTeam()->create(['name' => 'W '.$x]);
        $this->who['operator'] = User::factory()->withPersonalTeam()->platformOperator()->create(['name' => 'O '.$x]);
        $this->who['seller']->ownedTeams()->first()->forceFill(['name' => 'T '.$x])->save();

        $category = AuctionCategory::create(['name' => 'C '.$x, 'slug' => 'c-xss']);
        $this->lot = Auction::factory()->create([
            'seller_id' => $this->who['seller']->id, 'category_id' => $category->id, 'title' => 'Lot '.$x, 'description' => 'Desc '.$x."\n\n[link](javascript:alert(1))\n\n<b>bold</b>",
            'reserve_price' => '500.00', 'buy_now_price' => '900.00',
        ]);
        AuctionItem::create(['auction_id' => $this->lot->id, 'name' => 'Item '.$x, 'condition' => 'used '.$x, 'location' => 'Loc '.$x]);
        Bid::forceCreate(['auction_id' => $this->lot->id, 'bidder_id' => $this->who['bidder']->id, 'amount' => '250.00', 'is_winning' => true]);
        $this->lot->forceFill(['current_price' => '250.00'])->save();
        Watchlist::create(['user_id' => $this->who['bidder']->id, 'auction_id' => $this->lot->id]);

        $ended = Auction::factory()->ended()->create(['seller_id' => $this->who['seller']->id, 'title' => 'Ended '.$x, 'status' => 'ended']);
        $win = Bid::forceCreate(['auction_id' => $ended->id, 'bidder_id' => $this->who['winner']->id, 'amount' => '300.00', 'is_winning' => true]);
        $ended->forceFill(['current_price' => '300.00'])->save();
        $this->sale = AuctionSettlement::forceCreate([
            'auction_id' => $ended->id, 'winning_bid_id' => $win->id, 'seller_id' => $this->who['seller']->id, 'winner_id' => $this->who['winner']->id,
            'amount_cents' => 30000, 'currency' => 'ZAR', 'status' => AuctionSettlement::AWAITING_PAYMENT, 'payment_due_at' => now()->addDays(2),
        ]);
        $this->sale->events()->create(['user_id' => $this->who['seller']->id, 'type' => 'created', 'note' => 'Note '.$x]);

        $reserveLot = Auction::factory()->create(['seller_id' => $this->who['seller']->id, 'title' => 'Reserve '.$x, 'status' => 'pending_review', 'reserve_price' => '900.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour()]);
        $bid = Bid::forceCreate(['auction_id' => $reserveLot->id, 'bidder_id' => $this->who['bidder']->id, 'amount' => '400.00', 'is_winning' => true]);
        $proposal = ReserveNotMetProposal::forceCreate(['auction_id' => $reserveLot->id, 'bid_id' => $bid->id, 'reserve_price' => '900.00', 'status' => 'pending']);

        foreach (['seller', 'bidder', 'winner'] as $role) {
            $u = $this->who[$role];
            $u->notify(new OutbidNotification($bid));
            $u->notify(new AuctionWonNotification($ended, $win));
            $u->notify(new AuctionSoldNotification($ended, $this->sale));
            $u->notify(new AuctionEndingSoonNotification($this->lot));
            $u->notify(new ReserveNotMetNotification($proposal));
            $u->notify(new SettlementCancelledNotification($this->sale));
            $u->notify(new SettlementOverdueNotification($this->sale, 'winner'));
            $u->notify(new SettlementPaidNotification($this->sale, 'seller'));
            $u->notify(new SettlementPaymentOpenedNotification($this->sale));
        }
    }

    private function assertClean(string $html, string $where): void
    {
        foreach (self::NEEDLES as $needle) {
            $at = strpos($html, $needle);
            $this->assertFalse($at, "unescaped markup ({$needle}) on {$where}: ...".($at === false ? '' : substr($html, max(0, $at - 120), 260)).'...');
        }
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']?\s*javascript:/i', $html, "javascript: link on {$where}");
    }

    public function test_hostile_text_is_escaped_on_every_page_for_every_viewer(): void
    {
        $this->plant();
        $pages = [
            '/dashboard', '/auctions', '/auctions?q='.urlencode(self::XSS), '/auctions?state=live&sort=newest', '/auctions/'.$this->lot->id,
            '/bids', '/watchlist', '/won', '/notifications', '/seller/auctions', '/seller/auctions?state=live', '/seller/auctions/create',
            '/seller/auctions/'.$this->lot->id.'/edit', '/settlements', '/settlements/'.$this->sale->id, '/operator/settlement-payments',
            '/user/profile', '/teams/'.$this->who['seller']->ownedTeams()->first()->id,
        ];

        $seen = 0;
        foreach ($this->who as $role => $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user);

            foreach ($pages as $page) {
                $response = $this->get($page);
                $this->assertContains($response->getStatusCode(), [200, 403, 404], "{$role} {$page} answered ".$response->getStatusCode());

                if ($response->getStatusCode() === 200) {
                    $this->assertClean($response->getContent(), "{$role} {$page}");
                    $seen++;
                }
            }

            $this->assertClean(Livewire::test(NotificationBell::class)->set('open', true)->html(), "{$role} bell");
        }

        $this->assertGreaterThan(40, $seen, 'the sweep reached too few pages to mean anything');
    }

    public function test_the_hostile_title_does_appear_as_harmless_text(): void
    {
        // Guards the guard: if the pages stopped printing the title at all, the test above would pass for the wrong reason.
        $this->plant();
        $this->actingAs($this->who['bidder']);

        $this->get('/auctions')->assertSee(e('Lot '.self::XSS), false);
        $this->get('/auctions/'.$this->lot->id)->assertSee(e('Lot '.self::XSS), false);
    }

    public function test_markdown_mail_does_not_turn_a_team_name_into_a_link(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => '[Verify your account now](https://evil.example/phish)', 'personal_team' => false]);
        $invitation = $team->teamInvitations()->create(['email' => 'victim@example.test', 'role' => 'editor']);

        $html = (string) (new \Laravel\Jetstream\Mail\TeamInvitation($invitation))->render();

        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']?[^"\'>]*evil\.example/i', $html, 'the team name became a clickable link in the invitation mail');
    }
}
