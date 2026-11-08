<?php

namespace Tests\Feature\Security;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the bid panel (the history in particular) says about the other people on a lot: a masked name and an amount, to
 * the seller as to anyone else, and nothing about the reserve beyond met / not met.
 */
class BidHistoryExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_viewer_sees_masked_names_and_amounts_only_and_never_the_reserve_number(): void
    {
        $seller = User::factory()->withPersonalTeam()->create(['name' => 'Sandra Sellerfull', 'email' => 'sandra@hist.test']);
        $one = User::factory()->withPersonalTeam()->create(['name' => 'Thabo Nkosifull', 'email' => 'thabo@hist.test']);
        $two = User::factory()->withPersonalTeam()->create(['name' => "Aisha O'Brien-Patel", 'email' => 'aisha@hist.test']);
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'reserve_price' => '987.65', 'starting_price' => '100.00', 'current_price' => '120.00', 'bid_increment' => '10.00']);
        Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $one->id, 'amount' => '110.00', 'is_winning' => false]);
        Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $two->id, 'amount' => '120.00', 'is_winning' => true]);

        foreach ([$seller, $one, $two, User::factory()->withPersonalTeam()->create(), User::factory()->withPersonalTeam()->platformOperator()->create()] as $viewer) {
            $html = Livewire::actingAs($viewer)->test(BidPanel::class, ['auction' => $lot])->html();

            $this->assertStringContainsString('Thabo N.', $html);
            $this->assertStringContainsString('R120.00', $html);
            foreach (['Nkosifull', 'Brien-Patel', 'hist.test', '987', 'bidder_id'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "viewer {$viewer->id} can see '{$secret}' in the bid panel");
            }
        }
    }

    public function test_the_serialised_panel_state_carries_no_reserve_and_no_other_bidders(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $bidder = User::factory()->withPersonalTeam()->create(['name' => 'Hidden Person', 'email' => 'hidden@snap.test']);
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'reserve_price' => '987.65']);
        Bid::forceCreate(['auction_id' => $lot->id, 'bidder_id' => $bidder->id, 'amount' => '250.00', 'is_winning' => true]);
        $lot->forceFill(['current_price' => '250.00'])->save();

        $stranger = User::factory()->withPersonalTeam()->create();
        $component = Livewire::actingAs($stranger)->test(BidPanel::class, ['auction' => $lot]);
        $state = json_encode($component->snapshot ?? []);

        foreach (['987', 'reserve_price', 'hidden@snap.test', 'Hidden Person'] as $secret) {
            $this->assertStringNotContainsString($secret, $state, "the Livewire snapshot sent to the browser contains '{$secret}'");
        }
    }
}
