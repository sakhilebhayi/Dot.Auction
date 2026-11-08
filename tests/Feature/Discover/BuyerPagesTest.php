<?php

namespace Tests\Feature\Discover;

use App\Livewire\Buyer\WatchToggle;
use App\Models\Bid;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** My bids, Watching and Won: grouped by next step, own rows only, every control real. */
class BuyerPagesTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['/bids', '/watchlist', '/won', '/notifications'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_the_routes_the_shell_links_exist_and_answer(): void
    {
        $user = $this->person();
        foreach (['buyer.bids', 'buyer.watchlist', 'buyer.won'] as $name) {
            $this->assertTrue(auction_route_exists($name));
            $this->actingAs($user)->get(route($name))->assertOk();
        }
    }

    public function test_my_bids_groups_lots_by_the_next_step(): void
    {
        $me = $this->person();
        $rival = $this->person();

        $outbid = $this->lot(['title' => 'Group Outbid']);
        $this->bid($outbid, $me, '260.00');
        $this->bid($outbid, $rival, '300.00');
        $leading = $this->lot(['title' => 'Group Leading']);
        $this->bid($leading, $me, '260.00');
        $waiting = $this->awaitingDecision($this->person(), $me);
        $waiting->auction->forceFill(['title' => 'Group Waiting'])->save();
        $won = $this->endedLot($me, '400.00', ['title' => 'Group Won']);
        $this->sale($won, $me);
        $lost = $this->lot(['title' => 'Group Lost', 'status' => 'ended', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        Bid::forceCreate(['auction_id' => $lost->id, 'bidder_id' => $me->id, 'amount' => '450.00', 'is_winning' => false]);
        $this->bid($lost, $rival, '500.00');

        $response = $this->actingAs($me)->get('/bids')->assertOk();
        $html = $response->getContent();

        $section = fn (string $id) => explode('</section>', explode('id="'.$id.'"', $html)[1] ?? '')[0];
        $this->assertStringContainsString('Group Outbid', $section('outbid'));
        $this->assertStringContainsString('Bid again', $section('outbid'));
        $this->assertStringContainsString('Group Leading', $section('leading'));
        $this->assertStringContainsString('Group Waiting', $section('waiting'));
        $this->assertStringContainsString('Group Won', $section('ended'));
        $this->assertStringContainsString('You won', $section('ended'));
        $this->assertStringContainsString('Pay now', $section('ended'));
        $this->assertStringContainsString('Group Lost', $section('ended'));
        $this->assertStringContainsString('Not won', $section('ended'));
        foreach (['Group Outbid', 'Group Leading', 'Group Waiting'] as $title) {
            $this->assertStringNotContainsString($title, $section('ended'), "$title is not repeated under Ended");
        }
    }

    public function test_my_bids_shows_only_my_own_bids(): void
    {
        $me = $this->person();
        $other = $this->person();
        $this->bid($this->lot(['title' => 'Other Persons Bid']), $other, '260.00');

        $this->actingAs($me)->get('/bids')->assertOk()->assertDontSee('Other Persons Bid')->assertSee('You have not bid on anything yet');
    }

    public function test_the_ended_group_is_paginated_in_its_own_page_parameter(): void
    {
        $me = $this->person();
        foreach (range(1, 12) as $i) {
            $this->endedLot($me, '400.00', ['title' => "Ended Lot $i", 'ends_at' => now()->subDays($i)]);
        }

        $this->actingAs($me)->get('/bids')->assertOk()->assertSee('Ended Lot 1<', false)->assertDontSee('Ended Lot 12<', false)->assertSee('ended_page=2', false);
        $this->actingAs($me)->get('/bids?ended_page=2')->assertOk()->assertSee('Ended Lot 12<', false);
    }

    public function test_watching_lists_only_my_watched_lots_soonest_closing_first_with_a_remove_control(): void
    {
        $me = $this->person();
        $other = $this->person();
        $later = $this->lot(['title' => 'Watch Later', 'ends_at' => now()->addDays(3)]);
        $sooner = $this->lot(['title' => 'Watch Sooner', 'ends_at' => now()->addHours(3)]);
        $theirs = $this->lot(['title' => 'Someone Elses Watch']);
        foreach ([$later, $sooner] as $lot) {
            Watchlist::create(['user_id' => $me->id, 'auction_id' => $lot->id]);
        }
        Watchlist::create(['user_id' => $other->id, 'auction_id' => $theirs->id]);

        $this->actingAs($me)->get('/watchlist')->assertOk()
            ->assertSeeInOrder(['Watch Sooner', 'Watch Later'])
            ->assertDontSee('Someone Elses Watch')
            ->assertSee('action="'.route('buyer.watchlist.destroy', $sooner).'"', false)
            ->assertSee('Stop watching');
    }

    public function test_stop_watching_removes_only_my_row_and_says_so(): void
    {
        $me = $this->person();
        $other = $this->person();
        $lot = $this->lot(['title' => 'Stop Watching This']);
        Watchlist::create(['user_id' => $me->id, 'auction_id' => $lot->id]);
        Watchlist::create(['user_id' => $other->id, 'auction_id' => $lot->id]);

        $this->actingAs($me)->delete(route('buyer.watchlist.destroy', $lot))->assertRedirect(route('buyer.watchlist'));

        $this->assertSame(0, Watchlist::withoutGlobalScopes()->where('user_id', $me->id)->count());
        $this->assertSame(1, Watchlist::withoutGlobalScopes()->where('user_id', $other->id)->count(), "someone else's watch stays");
        $this->actingAs($me)->get('/watchlist')->assertSee('Stopped watching "Stop Watching This".')->assertSee('You are not watching any lots');
    }

    public function test_stop_watching_does_not_leak_another_sellers_draft_title(): void
    {
        $me = $this->person();
        $draft = $this->lot(['title' => 'Secret Draft', 'status' => 'draft']);

        $this->actingAs($me)->delete(route('buyer.watchlist.destroy', $draft))->assertNotFound();
    }

    public function test_won_shows_my_wins_with_their_sale_and_the_way_to_pay(): void
    {
        $me = $this->person();
        $other = $this->person();
        $unpaid = $this->endedLot($me, '400.00', ['title' => 'Won Unpaid']);
        $unpaidSale = $this->sale($unpaid, $me, 'awaiting_payment');
        $paid = $this->endedLot($me, '150.00', ['title' => 'Won Paid']);
        $this->sale($paid, $me, 'paid');
        $this->endedLot($other, '999.00', ['title' => 'Won By Someone Else']);

        $response = $this->actingAs($me)->get('/won')->assertOk();

        $response->assertSee('Won Unpaid')->assertSee('Won Paid')->assertDontSee('Won By Someone Else')
            ->assertSee('1 sale waits for payment')->assertSee('You owe R400.00 in total.')
            ->assertSee('href="'.route('settlements.show', $unpaidSale).'"', false)
            ->assertSee('Pay now')->assertSee('View sale');
    }

    public function test_a_lot_the_seller_rejected_is_not_listed_as_won(): void
    {
        $me = $this->person();
        $proposal = $this->awaitingDecision($this->person(), $me);
        $proposal->forceFill(['status' => 'rejected'])->save();
        $proposal->auction->forceFill(['status' => 'ended', 'title' => 'Rejected Lot'])->save();

        $this->actingAs($me)->get('/won')->assertOk()->assertDontSee('Rejected Lot')->assertSee('You have not won a lot yet');
    }

    public function test_a_won_lot_whose_sale_is_not_recorded_yet_says_so_rather_than_inventing_a_status(): void
    {
        $me = $this->person();
        $this->endedLot($me, '400.00', ['title' => 'Sale Pending Record']);

        $this->actingAs($me)->get('/won')->assertOk()->assertSee('Sale Pending Record')->assertSee('The sale is being recorded.');
    }

    public function test_the_watch_toggle_adds_and_removes_my_watch_and_names_the_lot(): void
    {
        $me = $this->person();
        $lot = $this->lot(['title' => 'Toggle Me']);

        $component = Livewire::actingAs($me)->test(WatchToggle::class, ['auctionId' => $lot->id, 'title' => $lot->title, 'watching' => false]);
        $component->assertSee('Watch')->assertSee('Toggle Me')->call('toggle')->assertSet('watching', true)->assertSee('Stop watching');
        $this->assertSame(1, Watchlist::withoutGlobalScopes()->where('user_id', $me->id)->where('auction_id', $lot->id)->count());

        $component->call('toggle')->assertSet('watching', false);
        $this->assertSame(0, Watchlist::withoutGlobalScopes()->where('user_id', $me->id)->count());
    }

    public function test_the_watch_toggle_refuses_your_own_lot_and_other_sellers_drafts_and_a_tampered_id(): void
    {
        $me = $this->person();
        $own = $this->lot([], $me);
        $draft = $this->lot(['status' => 'draft']);

        Livewire::actingAs($me)->test(WatchToggle::class, ['auctionId' => $own->id, 'title' => 'x', 'watching' => false])->call('toggle')->assertForbidden();
        Livewire::actingAs($me)->test(WatchToggle::class, ['auctionId' => $draft->id, 'title' => 'x', 'watching' => false])->call('toggle')->assertNotFound();
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        try {
            Livewire::actingAs($me)->test(WatchToggle::class, ['auctionId' => $own->id, 'title' => 'x', 'watching' => false])->set('auctionId', $draft->id);
        } finally {
            $this->assertSame(0, Watchlist::withoutGlobalScopes()->count());
        }
        $this->assertSame(0, Watchlist::withoutGlobalScopes()->count());
    }
}
