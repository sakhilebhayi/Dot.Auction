<?php

namespace Tests\Feature\R42;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * The real-browser run (tests/e2e/auction, spec b9) found that a Livewire call that failed said nothing: a lost connection did
 * nothing visible and left the lot page's poll waiting for ever, and a 500 opened a full-screen copy of the error page. The fix lives
 * in resources/js/auction-live.js and needs the toast region on every signed-in page. PHPUnit cannot run the script, so this pins
 * its two inputs; the behaviour itself is proved in the browser (b9).
 */
class LivewireFailureNoticeTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_every_signed_in_page_carries_the_toast_region_the_failure_notices_use(): void
    {
        $me = $this->person();
        $lot = $this->lot();

        foreach (['/dashboard', '/auctions', route('auctions.show', $lot), '/bids'] as $url) {
            $html = $this->actingAs($me)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-ds-toasts', $html, $url);
            $this->assertStringContainsString('id="ds-toast-template"', $html, $url);
        }
    }

    public function test_the_script_handles_failed_livewire_calls_in_words(): void
    {
        $js = file_get_contents(resource_path('js/auction-live.js'));

        $this->assertStringContainsString("Livewire.hook('request'", $js);
        $this->assertStringContainsString('dot:network-lost', $js, 'a lost connection must release the lot page poll');
        $this->assertStringContainsString('No connection', $js);
        $this->assertStringContainsString('Something went wrong on our side', $js);
        $this->assertStringContainsString('This page has expired', $js);
        $this->assertStringContainsString('Slow down a little', $js, 'the per-person Livewire limit answers 429');
        $this->assertStringContainsString('preventDefault()', $js, 'the raw error page must not open in Livewire\'s modal');
    }
}
