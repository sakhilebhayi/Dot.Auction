<?php

namespace Tests\Feature\R42;

use App\Models\AuctionImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignedInSmoke;
use Tests\TestCase;

/**
 * Every authenticated page answers 200 for the person meant to see it. The same check runs against cached routes,
 * config and events with `sh tests/Support/signed-in-smoke.sh` (production boots that way); if this suite is itself run
 * after `route:cache`, it exercises the cached routes too.
 */
class SignedInSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authenticated_page_answers_200_for_the_right_person(): void
    {
        Storage::fake(AuctionImage::DISK);
        $smoke = new SignedInSmoke;
        $people = $smoke->seed();

        foreach ($smoke->pages() as $page) {
            // A new browser per page: nothing left over from the previous person in the (array) session.
            $this->app['session.store']->flush();
            $this->app['auth']->forgetGuards();

            $status = $this->actingAs($people[$page['who']])->get($page['uri'])->getStatusCode();

            $this->assertSame(200, $status, "{$page['who']} could not open {$page['uri']}");
        }
    }

    public function test_no_authenticated_page_is_left_out_of_the_smoke_list(): void
    {
        Storage::fake(AuctionImage::DISK);
        $smoke = new SignedInSmoke;
        $smoke->seed();

        $this->assertSame([], $smoke->uncoveredRoutes(), 'Open these in SignedInSmoke::pages() or explain them in SignedInSmoke::SKIPPED.');
    }
}
