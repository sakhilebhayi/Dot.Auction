<?php

namespace Tests\Feature\Audit;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * Who can reach what, written as one matrix so a new route or a loosened policy shows up in a single place.
 * Roles: guest, a stranger (signed in, no relation), the seller, a member of the seller's team, the winner,
 * a losing bidder, an operator.
 */
class AccessMatrixTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    /** Route-name prefixes of this application's own routes (Fortify/Jetstream/Livewire ship their own tests). */
    private const OWN = ['dashboard', 'auctions.', 'seller.', 'settlements.', 'operator.'];

    private function ownRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->getName() !== null && Str::startsWith($r->getName(), self::OWN))
            ->values()
            ->all();
    }

    private function fill(string $uri): string
    {
        return preg_replace(['/\{auction\}/', '/\{image\}/', '/\{settlement\}/'], ['1', '1', '00000000-0000-0000-0000-000000000000'], $uri);
    }

    public function test_the_expected_set_of_application_routes_exists(): void
    {
        $names = array_map(fn ($r) => $r->getName(), $this->ownRoutes());

        // A route added without being thought about fails here; add it to the matrix below when this list changes.
        $this->assertEqualsCanonicalizing([
            'dashboard',
            'auctions.index', 'auctions.show', 'auctions.images.show',
            'seller.auctions.index', 'seller.auctions.create', 'seller.auctions.store', 'seller.auctions.edit',
            'seller.auctions.update', 'seller.auctions.publish', 'seller.auctions.cancel', 'seller.auctions.destroy',
            'seller.auctions.images.store', 'seller.auctions.images.destroy',
            'settlements.index', 'settlements.show', 'settlements.pay', 'settlements.payment.callback', 'settlements.cancel',
            'operator.settlement-payments.index',
        ], $names);
    }

    public function test_every_application_route_sends_a_guest_to_sign_in(): void
    {
        foreach ($this->ownRoutes() as $route) {
            $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD'], true));
            $response = $this->call($method, $this->fill('/'.ltrim($route->uri(), '/')));

            $this->assertSame(302, $response->getStatusCode(), $method.' '.$route->uri().' answered '.$response->getStatusCode().' to a guest');
            $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'), $route->uri());
        }
    }

    public function test_a_stranger_cannot_touch_another_sellers_listing(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger);

        $this->get(route('seller.auctions.edit', $auction))->assertForbidden();
        $this->put(route('seller.auctions.update', $auction), ['title' => 'Hijacked'])->assertForbidden();
        $this->post(route('seller.auctions.publish', $auction))->assertForbidden();
        $this->post(route('seller.auctions.cancel', $auction))->assertForbidden();
        $this->delete(route('seller.auctions.destroy', $auction))->assertForbidden();
        $this->post(route('seller.auctions.images.store', $auction), ['images' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();

        $this->assertSame('active', $auction->fresh()->status);
        $this->assertNotSame('Hijacked', $auction->fresh()->title);
    }

    public function test_a_member_of_the_sellers_team_has_no_extra_rights_over_the_sellers_listing(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $team = $seller->ownedTeams()->first();
        $member = User::factory()->withPersonalTeam()->create();
        $team->users()->attach($member, ['role' => 'admin']);
        $auction = Auction::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($member)->get(route('seller.auctions.edit', $auction))->assertForbidden();
        $this->post(route('seller.auctions.cancel', $auction))->assertForbidden();
        $this->assertInstanceOf(Team::class, $team);
    }

    public function test_a_platform_operator_gets_no_listing_powers(): void
    {
        $auction = Auction::factory()->create();
        $operator = User::factory()->withPersonalTeam()->platformOperator()->create();

        $this->actingAs($operator)->get(route('seller.auctions.edit', $auction))->assertForbidden();
        $this->post(route('seller.auctions.cancel', $auction))->assertForbidden();
    }

    public function test_only_the_two_parties_can_see_a_settlement_and_each_may_only_do_their_own_part(): void
    {
        $this->enablePaystack();
        [$settlement, $winner, $seller] = $this->settlement();
        $stranger = User::factory()->withPersonalTeam()->create();
        $loser = User::factory()->withPersonalTeam()->create();
        $operator = User::factory()->withPersonalTeam()->platformOperator()->create();

        foreach ([$stranger, $loser, $operator] as $outsider) {
            $this->actingAs($outsider);
            $this->get(route('settlements.show', $settlement))->assertNotFound();
            $this->post(route('settlements.pay', $settlement))->assertNotFound();
            $this->get(route('settlements.payment.callback', $settlement))->assertNotFound();
            $this->post(route('settlements.cancel', $settlement))->assertNotFound();
        }

        $this->actingAs($seller)->post(route('settlements.pay', $settlement))->assertForbidden();
        $this->get(route('settlements.payment.callback', $settlement))->assertForbidden();
        $this->actingAs($winner)->post(route('settlements.cancel', $settlement))->assertForbidden();

        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_a_settlement_never_appears_in_anyone_elses_list(): void
    {
        [$settlement, $winner, $seller] = $this->settlement();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->get(route('settlements.index'))->assertOk()->assertDontSee(route('settlements.show', $settlement));
        $this->actingAs($winner)->get(route('settlements.index'))->assertOk()->assertSee(route('settlements.show', $settlement));
        $this->actingAs($seller)->get(route('settlements.index'))->assertOk()->assertSee(route('settlements.show', $settlement));
    }

    public function test_the_auction_page_shows_a_settlement_panel_to_its_two_parties_only(): void
    {
        [$settlement, $winner, $seller] = $this->settlement();
        $auction = $settlement->auction;
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->get(route('auctions.show', $auction))->assertOk()->assertDontSee('Payment pending');
        $this->actingAs($winner)->get(route('auctions.show', $auction))->assertOk()->assertSee('Payment pending');
        $this->actingAs($seller)->get(route('auctions.show', $auction))->assertOk()->assertSee('Payment pending');
    }

    public function test_a_draft_is_invisible_to_everyone_but_its_seller_including_its_photos(): void
    {
        Storage::fake(AuctionImage::DISK);
        $seller = User::factory()->withPersonalTeam()->create();
        $draft = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        Storage::disk(AuctionImage::DISK)->put('x/draft.jpg', 'jpeg');
        $image = AuctionImage::create(['auction_id' => $draft->id, 'path' => 'x/draft.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'width' => 1, 'height' => 1]);

        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->get(route('auctions.show', $draft))->assertNotFound();
        $this->get(route('auctions.images.show', [$draft, $image]))->assertNotFound();

        $this->actingAs($seller);
        $this->get(route('auctions.show', $draft))->assertOk();
        $this->get(route('auctions.images.show', [$draft, $image]))->assertOk();
    }

    public function test_the_reserve_price_is_never_in_a_buyers_page(): void
    {
        $auction = Auction::factory()->create(['reserve_price' => '987654.32', 'starting_price' => '200.00', 'current_price' => '200.00']);
        $buyer = User::factory()->withPersonalTeam()->create();

        $this->actingAs($buyer)->get(route('auctions.show', $auction))->assertOk()
            ->assertDontSee('987654')->assertDontSee('987 654')->assertSee('Reserve not yet met');
        $this->get(route('auctions.index'))->assertOk()->assertDontSee('987654');
        $this->assertArrayNotHasKey('reserve_price', $auction->toArray());
    }

    public function test_bid_history_shows_masked_names_never_emails_or_full_names(): void
    {
        $auction = Auction::factory()->create();
        $bidder = User::factory()->create(['name' => 'Thandiwe Zulu-Nkosi', 'email' => 'thandi.private@example.test']);
        Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => $bidder->id, 'amount' => 250, 'is_winning' => true]);

        $page = $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->get(route('auctions.show', $auction))->assertOk()->getContent();

        $this->assertStringContainsString('Thandiwe Z.', preg_replace('/\s+/', ' ', $page));
        $this->assertStringNotContainsString('Zulu-Nkosi', $page);
        $this->assertStringNotContainsString('thandi.private', $page);
    }
}
