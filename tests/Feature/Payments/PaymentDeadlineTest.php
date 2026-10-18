<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Notifications\SettlementOverdueNotification;
use App\Notifications\SettlementPaymentOpenedNotification;
use App\Services\Auctions\BidPlacer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class PaymentDeadlineTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->enablePaystack();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function run15(): void
    {
        $this->artisan('auction:mark-overdue-payments')->assertSuccessful();
    }

    public function test_an_unpaid_settlement_past_its_deadline_becomes_overdue_and_both_parties_are_told(): void
    {
        [$settlement, $winner, $seller] = $this->settlement(100000, ['payment_due_at' => now()->subMinute()]);

        $this->run15();

        $settlement->refresh();
        $this->assertSame(AuctionSettlement::PAYMENT_OVERDUE, $settlement->status);
        $this->assertTrue($settlement->overdue_at->equalTo(now()));
        $won = $winner->notifications()->where('type', SettlementOverdueNotification::class)->sole()->data;
        $sold = $seller->notifications()->where('type', SettlementOverdueNotification::class)->sole()->data;
        $this->assertStringContainsString('can still pay', $won['message']);
        $this->assertStringContainsString('cancel', strtolower($sold['message']));
        $this->assertSame(1, $settlement->events()->where('type', 'overdue')->count());
    }

    public function test_the_deadline_boundary_is_inclusive_and_not_early(): void
    {
        [$exact] = $this->settlement(100000, ['payment_due_at' => now()]);
        [$early] = $this->settlement(100000, ['payment_due_at' => now()->addSecond()]);

        $this->run15();

        $this->assertSame(AuctionSettlement::PAYMENT_OVERDUE, $exact->fresh()->status);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $early->fresh()->status);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->run15();
        $this->assertSame(AuctionSettlement::PAYMENT_OVERDUE, $early->fresh()->status);
    }

    public function test_running_it_again_is_idempotent_and_never_notifies_twice(): void
    {
        [$settlement, $winner, $seller] = $this->settlement(100000, ['payment_due_at' => now()->subHour()]);

        $this->run15();
        $first = $settlement->fresh()->overdue_at;
        Carbon::setTestNow(now()->addHours(5));
        $this->run15();
        $this->run15();

        $this->assertEquals($first, $settlement->fresh()->overdue_at);
        $this->assertSame(1, $winner->notifications()->where('type', SettlementOverdueNotification::class)->count());
        $this->assertSame(1, $seller->notifications()->where('type', SettlementOverdueNotification::class)->count());
        $this->assertSame(1, $settlement->events()->where('type', 'overdue')->count());
    }

    public function test_a_paid_or_cancelled_settlement_is_never_changed(): void
    {
        [$paid, $paidWinner] = $this->settlement(100000, ['status' => AuctionSettlement::PAID, 'paid_at' => now()->subDay(), 'payment_due_at' => now()->subDay()]);
        [$cancelled] = $this->settlement(100000, ['status' => AuctionSettlement::CANCELLED, 'cancelled_at' => now()->subDay(), 'payment_due_at' => now()->subDay()]);

        $this->run15();

        $this->assertSame(AuctionSettlement::PAID, $paid->fresh()->status);
        $this->assertNull($paid->fresh()->overdue_at);
        $this->assertSame(AuctionSettlement::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(0, $paidWinner->notifications()->count());
    }

    public function test_a_payment_that_lands_just_before_the_run_wins_the_race(): void
    {
        [$settlement, $winner] = $this->settlement(100000, ['payment_due_at' => now()->subMinute()]);
        $payment = $this->attempt($settlement);
        Http::fake();

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->run15();

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(0, $winner->notifications()->where('type', SettlementOverdueNotification::class)->count());
    }

    public function test_the_auction_is_never_relisted_or_cancelled_automatically(): void
    {
        [$settlement] = $this->settlement(100000, ['payment_due_at' => now()->subDay()]);
        $auctionsBefore = Auction::count();

        $this->run15();

        $this->assertSame('ended', $settlement->auction->fresh()->status);
        $this->assertSame($auctionsBefore, Auction::count());
        $this->assertSame(AuctionSettlement::PAYMENT_OVERDUE, $settlement->fresh()->status);
    }

    public function test_without_payments_nothing_goes_overdue_because_the_winner_could_not_have_paid(): void
    {
        $this->disablePaystack();
        [$settlement, $winner] = $this->settlement(100000, ['payment_due_at' => now()->subDay()]);

        $this->run15();

        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
        $this->assertSame(0, $winner->notifications()->count());
    }

    public function test_the_clock_starts_when_payment_opens_for_settlements_recorded_before_it_was_available(): void
    {
        [$waiting, $winner] = $this->settlement(100000, ['payment_due_at' => null, 'created_at' => now()->subDays(10)]);

        $this->run15();

        $waiting->refresh();
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $waiting->status);
        $this->assertTrue($waiting->payment_due_at->equalTo(now()->addHours(72)));
        $note = $winner->notifications()->where('type', SettlementPaymentOpenedNotification::class)->sole()->data;
        $this->assertStringContainsString('72 hours', $note['message']);

        $this->run15();
        $this->assertSame(1, $winner->notifications()->where('type', SettlementPaymentOpenedNotification::class)->count());
        $this->assertTrue($waiting->fresh()->payment_due_at->equalTo(now()->addHours(72)));
    }

    public function test_the_window_is_configurable(): void
    {
        config(['auction.payment_window_hours' => 24]);
        [$waiting] = $this->settlement(100000, ['payment_due_at' => null]);

        $this->run15();

        $this->assertTrue($waiting->fresh()->payment_due_at->equalTo(now()->addHours(24)));
    }

    public function test_a_settlement_recorded_while_payments_are_on_gets_its_deadline_at_once(): void
    {
        $auction = Auction::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10)]);
        $winner = User::factory()->withPersonalTeam()->create();
        app(BidPlacer::class)->place($auction, $winner, '220.00');
        $auction->update(['ends_at' => now()->subMinute()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $settlement = AuctionSettlement::sole();
        $this->assertTrue($settlement->payment_due_at->equalTo(now()->addHours(72)));
    }

    public function test_a_settlement_recorded_while_payments_are_off_has_no_deadline_yet(): void
    {
        $this->disablePaystack();
        $auction = Auction::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->addMinutes(10)]);
        app(BidPlacer::class)->place($auction, User::factory()->withPersonalTeam()->create(), '220.00');
        $auction->update(['ends_at' => now()->subMinute()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertNull(AuctionSettlement::sole()->payment_due_at);
    }

    public function test_it_is_scheduled_every_fifteen_minutes_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'auction:mark-overdue-payments'));

        $this->assertNotNull($event, 'the command must be scheduled');
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_marking_overdue_expires_no_payment_so_a_winner_in_the_middle_of_paying_is_not_cut_off(): void
    {
        [$settlement] = $this->settlement(100000, ['payment_due_at' => now()->subMinute()]);
        $payment = $this->attempt($settlement);

        $this->run15();

        $this->assertSame(SettlementPayment::INITIATED, $payment->fresh()->status);
    }
}
