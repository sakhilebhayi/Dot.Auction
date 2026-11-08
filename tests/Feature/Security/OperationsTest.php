<?php

namespace Tests\Feature\Security;

use App\Console\Commands\SeedCategories;
use App\Events\AuctionSettled;
use App\Listeners\RecordSettlementForPayment;
use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the host's one cron line has to keep running, and the one-time provisioning a new deployment needs.
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_job_the_platform_depends_on_is_scheduled(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [trim(str_replace([PHP_BINARY, "'artisan'", 'artisan', '"', "'"], '', (string) $e->command)) => $e->expression]);

        $expect = [
            'auction:settle-ended' => '*/5 * * * *',       // closes auctions, picks the winner, hands off to payment
            'auction:repair-unsettled' => '0 * * * *',      // recovers a lost hand-off (logged at error level)
            'auction:notify-ending-soon' => '*/5 * * * *',  // watchers' "closing soon" notices
            'auction:mark-overdue-payments' => '*/15 * * * *',
            'auction:publish-metrics' => '15 0 * * *',
            'queue:prune-failed --hours=336' => '0 0 * * *',
            'auth:clear-resets' => '0 0 * * *',
        ];

        foreach ($expect as $command => $cron) {
            $this->assertTrue($scheduled->has($command), "'{$command}' is not scheduled. Scheduled: ".$scheduled->keys()->implode(' | '));
            $this->assertSame($cron, $scheduled[$command], $command);
        }
    }

    public function test_the_failure_paths_log_at_error_level_or_report(): void
    {
        $source = fn (string $path) => (string) file_get_contents(base_path($path));

        $this->assertStringContainsString('report($e)', $source('app/Console/Commands/SettleEndedAuctions.php'), 'a settlement failure is reported to the application log');
        $this->assertStringContainsString('Log::error(', $source('app/Console/Commands/RepairUnsettledAuctions.php'));
        $this->assertStringContainsString('report($e)', $source('app/Console/Commands/RepairUnsettledAuctions.php'));
    }

    public function test_a_settlement_hand_off_that_fails_every_retry_is_logged_at_error_level(): void
    {
        Log::spy();
        $lot = Auction::factory()->ended()->create();
        $listener = app(RecordSettlementForPayment::class);

        $listener->failed(new AuctionSettled($lot, null), new \RuntimeException('database went away'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'auction:repair-unsettled') && ($context['auction_id'] ?? null) === $lot->id)->once();
    }

    public function test_the_category_command_is_idempotent_and_never_touches_what_is_there(): void
    {
        AuctionCategory::create(['name' => 'Cameras (renamed by a person)', 'slug' => 'cameras-photography']);
        AuctionCategory::create(['name' => 'Locally invented', 'slug' => 'locally-invented']);

        Artisan::call('auction:seed-categories', ['--dry-run' => true]);
        $this->assertSame(2, AuctionCategory::count(), 'a dry run writes nothing');

        $this->assertSame(0, Artisan::call('auction:seed-categories'));
        $first = AuctionCategory::count();
        $this->assertSame(count(SeedCategories::DEFAULTS) + 1, $first, 'every default is present once; the renamed one counts as its default and the invented one stays');
        $this->assertSame('Cameras (renamed by a person)', AuctionCategory::where('slug', 'cameras-photography')->value('name'), 'existing rows are never renamed');
        $this->assertTrue(AuctionCategory::where('slug', 'locally-invented')->exists());

        Artisan::call('auction:seed-categories');
        $this->assertSame($first, AuctionCategory::count(), 'a second run adds nothing');
        $this->assertSame(AuctionCategory::count(), AuctionCategory::distinct()->count('slug'));
    }

    public function test_the_category_check_fails_on_an_empty_table_and_passes_otherwise(): void
    {
        $this->assertSame(1, Artisan::call('auction:seed-categories', ['--check' => true]));

        Artisan::call('auction:seed-categories');
        $this->assertSame(0, Artisan::call('auction:seed-categories', ['--check' => true]));
    }

    public function test_the_default_categories_have_distinct_clean_slugs_and_a_listing_can_use_one(): void
    {
        $slugs = array_map(fn ($n) => Str::slug($n), SeedCategories::DEFAULTS);
        $this->assertSame($slugs, array_values(array_unique($slugs)));
        foreach ($slugs as $slug) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
        }

        Artisan::call('auction:seed-categories');
        $seller = User::factory()->withPersonalTeam()->create();
        $this->actingAs($seller)->get(route('seller.auctions.create'))->assertOk()->assertSee('Antiques &amp; Collectables', false);
    }

    public function test_an_empty_category_table_is_visible_in_the_log_when_a_seller_opens_the_form(): void
    {
        Log::spy();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('seller.auctions.create'))->assertOk();

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains((string) $message, 'auction:seed-categories'))->once();
        $this->assertSame(0, Auction::count());
    }
}
