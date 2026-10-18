<?php

use App\Console\Commands\MarkOverduePayments;
use App\Console\Commands\NotifyEndingSoonWatchers;
use App\Console\Commands\PublishMetrics;
use App\Console\Commands\SettleEndedAuctions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-auction-settlement-reserve-gate.md.
Schedule::command(SettleEndedAuctions::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Once per watcher per auction (the watch row carries the marker), so the
// cadence only decides how late a notice can be, never how many go out.
Schedule::command(NotifyEndingSoonWatchers::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Starts the payment clock for sales recorded before online payment was on, and marks unpaid sales
// past their deadline as overdue. It never relists or cancels anything.
Schedule::command(MarkOverduePayments::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// One signed aggregate snapshot per company to Dot.Analytics, just after the UTC day turns over.
// taken_at is the start of that UTC day, so a re-run the same day is the same snapshot upstream.
// Does nothing until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are set; needs the host's
// one-minute scheduler cron and a queue worker.
Schedule::command(PublishMetrics::class)
    ->dailyAt('00:15')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
