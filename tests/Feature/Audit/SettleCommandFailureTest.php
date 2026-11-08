<?php

namespace Tests\Feature\Audit;

use App\Models\Auction;
use App\Services\Auctions\AuctionSettler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * cron-scheduler.log is rewritten every minute, so the only lasting record of a settlement that keeps failing is
 * the application log. The command used to print the error and swallow it: the auction stayed "active" and
 * retried forever with nobody able to tell.
 */
class SettleCommandFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failing_settlement_is_reported_and_does_not_stop_the_others(): void
    {
        Exceptions::fake();

        $bad = Auction::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->subMinute()]);
        $good = Auction::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->subMinute()]);

        $real = app(AuctionSettler::class);
        $this->app->instance(AuctionSettler::class, new class($bad->id, $real) extends AuctionSettler
        {
            public function __construct(private int $badId, private AuctionSettler $real) {}

            public function settle(Auction|int $auction): bool
            {
                if ($auction === $this->badId) {
                    throw new RuntimeException('database hiccup');
                }

                return $this->real->settle($auction);
            }
        });

        $this->artisan('auction:settle-ended')->assertFailed();

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'database hiccup');
        $this->assertSame('ended', $good->fresh()->status);
        $this->assertSame('active', $bad->fresh()->status, 'left as it was, so the next run retries it');
    }

    public function test_a_clean_run_still_succeeds(): void
    {
        Auction::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->subMinute()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();
    }
}
