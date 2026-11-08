<?php

namespace App\Console\Commands;

use Database\Seeders\AuctionDemoSeeder;
use Illuminate\Console\Command;

/**
 * Builds the fictional South African demo world (users, lots in every status, bids, photos, watchlists,
 * notifications, settlements in every state) for local design and QA work. See docs/R42_DEMO.md.
 */
class SeedDemoAuctions extends Command
{
    protected $signature = 'auction:demo-seed {--fresh : Remove the demo accounts and everything they own, then rebuild (lots are timed relative to now)}';

    protected $description = 'Seed a fictional demo world for local development. Refuses to run in production.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run: the demo seeder never runs in production.');

            return self::FAILURE;
        }

        (new AuctionDemoSeeder(fresh: (bool) $this->option('fresh')))
            ->setContainer($this->laravel)
            ->setCommand($this)
            ->run();

        return self::SUCCESS;
    }
}
