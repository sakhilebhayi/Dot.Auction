<?php

use App\Console\Commands\NotifyEndingSoonWatchers;
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
