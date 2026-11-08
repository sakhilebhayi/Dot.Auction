<?php

namespace Tests\Feature\R42;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/** Found by the real-browser run: "Brass ship's lantern" was printed as "Brass ship&#039;s lantern" in the lot page's h1. */
class LotTitleEscapingTest extends TestCase
{
    use BuildsDiscoverWorld;
    use RefreshDatabase;

    public function test_the_lot_heading_escapes_the_title_once(): void
    {
        $title = 'Ship\'s "lantern" & <b>brass</b>';
        $lot = $this->lot(['title' => $title]);

        $html = $this->actingAs($this->person())->get(route('auctions.show', $lot))->assertOk()->getContent();

        preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $m);
        $this->assertSame(e($title), trim($m[1] ?? ''), 'the h1 must hold the title escaped exactly once');
        $this->assertStringNotContainsString('&amp;#039;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('<b>brass</b>', $html);
        $this->assertStringContainsString('<title>'.e($title).' | ', $html);
    }
}
