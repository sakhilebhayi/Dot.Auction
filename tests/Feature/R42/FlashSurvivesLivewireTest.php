<?php

namespace Tests\Feature\R42;

use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Found by the real-browser run: the bell and the lot page poll with Livewire, and a poll that lands between a redirect and
 * the page it redirects to spent the flash message ("Stopped watching ...", "Payment received") on a response nobody reads.
 */
class FlashSurvivesLivewireTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_a_livewire_call_between_a_redirect_and_its_page_does_not_spend_the_flash_message(): void
    {
        $me = $this->person();
        $lot = $this->lot(['title' => 'Flash lot']);
        Watchlist::create(['user_id' => $me->id, 'auction_id' => $lot->id]);

        $this->actingAs($me)->delete(route('buyer.watchlist.destroy', $lot))->assertRedirect(route('buyer.watchlist'));

        // A poll arrives first (any Livewire call: the payload does not matter, the header is what Livewire sends).
        $this->postJson('/livewire/update', ['components' => []], ['X-Livewire' => 'true']);

        $this->get(route('buyer.watchlist'))->assertOk()->assertSee('Stopped watching', false);
    }

    public function test_an_ordinary_request_in_between_still_ages_the_flash_message_as_before(): void
    {
        $me = $this->person();
        $lot = $this->lot(['title' => 'Flash lot two']);
        Watchlist::create(['user_id' => $me->id, 'auction_id' => $lot->id]);

        $this->actingAs($me)->delete(route('buyer.watchlist.destroy', $lot));
        $this->get(route('buyer.won'))->assertOk();   // an ordinary page request reads and spends it, as Laravel always did

        $this->get(route('buyer.watchlist'))->assertOk()->assertDontSee('Stopped watching', false);
    }

    public function test_a_click_anywhere_does_not_call_the_server_while_the_bell_is_closed(): void
    {
        // The bell used to call close() on the server for every click outside it, panel open or not. That call raced the form
        // post of whatever was clicked (a redirect's flash message was overwritten by the call's stale copy of the session).
        $html = \Livewire\Livewire::actingAs($this->person())->test(\App\Livewire\NotificationBell::class)->html();

        $this->assertStringContainsString('@click.outside="if ($wire.open) { $wire.close() }"', html_entity_decode($html, ENT_QUOTES));
        $this->assertStringNotContainsString('@click.outside="$wire.close()"', html_entity_decode($html, ENT_QUOTES));
    }
}
