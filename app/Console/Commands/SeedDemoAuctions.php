<?php

namespace App\Console\Commands;

use Database\Seeders\AuctionDemoSeeder;
use Illuminate\Console\Command;

/**
 * Builds the fictional South African demo world (users, lots in every status, bids, photos, watchlists,
 * notifications, sales awaiting payment) for local design and QA work. With --payments it also writes the states that exist only
 * while online payment is on (started and failed attempts, paid, overdue and cancelled sales), for the run of that command only. See docs/R42_DEMO.md.
 */
class SeedDemoAuctions extends Command
{
    protected $signature = 'auction:demo-seed {--fresh : Remove the demo accounts and everything they own, then rebuild (lots are timed relative to now)}
        {--payments : Build the world as if online payment were on (fake test key, nothing is sent): started, failed, paid, overdue and cancelled sales with matching notices}';

    protected $description = 'Seed a fictional demo world for local development. Refuses to run in production.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run: the demo seeder never runs in production.');

            return self::FAILURE;
        }

        (new AuctionDemoSeeder(fresh: (bool) $this->option('fresh'), payments: (bool) $this->option('payments')))
            ->setContainer($this->laravel)
            ->setCommand($this)
            ->run();

        return self::SUCCESS;
    }
}
