<?php

namespace Tests\Feature\Audit;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler is the only thing that closes auctions. On a shared host the process can be killed mid-run, and a
 * withoutOverlapping() lock with its default 24 hour lifetime then blocks every later run for a day.
 */
class ScheduleSafetyTest extends TestCase
{
    /**
     * @return array<int, Event>
     */
    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    public function test_every_overlap_lock_expires_within_the_hour(): void
    {
        $this->assertNotEmpty($this->events());

        foreach ($this->events() as $event) {
            $this->assertTrue($event->withoutOverlapping, $event->command.' should not overlap itself');
            $this->assertLessThanOrEqual(60, $event->expiresAt, $event->command.' holds its overlap lock for '.$event->expiresAt.' minutes');
        }
    }

    public function test_settlement_and_ending_soon_run_every_five_minutes(): void
    {
        $byCommand = [];
        foreach ($this->events() as $event) {
            $byCommand[$event->command] = $event->expression;
        }

        $settle = collect($byCommand)->first(fn ($e, $c) => str_contains($c, 'auction:settle-ended'));
        $ending = collect($byCommand)->first(fn ($e, $c) => str_contains($c, 'auction:notify-ending-soon'));
        $overdue = collect($byCommand)->first(fn ($e, $c) => str_contains($c, 'auction:mark-overdue-payments'));

        $this->assertSame('*/5 * * * *', $settle);
        $this->assertSame('*/5 * * * *', $ending);
        $this->assertSame('*/15 * * * *', $overdue);
    }
}
