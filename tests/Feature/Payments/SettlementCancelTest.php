<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Notifications\SettlementCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class SettlementCancelTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
    }

    private function overdue(): array
    {
        return $this->settlement(100000, ['status' => AuctionSettlement::PAYMENT_OVERDUE, 'overdue_at' => now()->subDay(), 'payment_due_at' => now()->subDay()]);
    }

    public function test_the_seller_can_cancel_an_overdue_settlement_and_it_is_audited(): void
    {
        [$settlement, $winner, $seller] = $this->overdue();
        $live = $this->attempt($settlement);

        $this->actingAs($seller)->post(route('settlements.cancel', $settlement))
            ->assertRedirect(route('settlements.show', $settlement))->assertSessionHas('status');

        $settlement->refresh();
        $this->assertSame(AuctionSettlement::CANCELLED, $settlement->status);
        $this->assertNotNull($settlement->cancelled_at);
        $this->assertSame($seller->id, $settlement->cancelled_by);
        $event = $settlement->events()->where('type', 'cancelled')->sole();
        $this->assertSame($seller->id, $event->user_id);
        $this->assertSame(SettlementPayment::EXPIRED, $live->fresh()->status);
        $this->assertSame(1, $winner->notifications()->where('type', SettlementCancelledNotification::class)->count());
        $this->assertSame('ended', $settlement->auction->fresh()->status, 'cancelling the settlement never relists or changes the auction');
    }

    public function test_cancel_is_only_offered_to_the_seller_and_only_once_overdue(): void
    {
        [$overdue, , $seller] = $this->overdue();
        [$awaiting, , $seller2] = $this->settlement();

        $this->actingAs($seller)->get(route('settlements.show', $overdue))
            ->assertOk()->assertSee('Cancel this sale')->assertSee(route('settlements.cancel', $overdue), false)->assertSee('does not relist');
        $this->actingAs($seller2)->get(route('settlements.show', $awaiting))
            ->assertOk()->assertDontSee('Cancel this sale');
    }

    public function test_the_winner_never_sees_or_uses_cancel(): void
    {
        [$settlement, $winner] = $this->overdue();

        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertOk()->assertDontSee('Cancel this sale');
        $this->actingAs($winner)->post(route('settlements.cancel', $settlement))->assertForbidden();
        $this->assertSame(AuctionSettlement::PAYMENT_OVERDUE, $settlement->fresh()->status);
    }

    public function test_an_outsider_gets_a_404_and_a_guest_is_sent_to_login(): void
    {
        [$settlement] = $this->overdue();

        $this->actingAs(User::factory()->withPersonalTeam()->create())->post(route('settlements.cancel', $settlement))->assertNotFound();
        $this->asGuest()->post(route('settlements.cancel', $settlement))->assertRedirect('/login');
        $this->assertSame(AuctionSettlement::PAYMENT_OVERDUE, $settlement->fresh()->status);
    }

    public function test_a_settlement_that_is_not_overdue_cannot_be_cancelled(): void
    {
        foreach ([AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAID] as $status) {
            [$settlement, , $seller] = $this->settlement(100000, ['status' => $status]);

            $this->actingAs($seller)->post(route('settlements.cancel', $settlement))->assertSessionHasErrors('cancel');

            $this->assertSame($status, $settlement->fresh()->status);
            $this->assertNull($settlement->fresh()->cancelled_at);
            $this->assertSame(0, $settlement->events()->count());
        }
    }

    public function test_cancelling_twice_changes_and_notifies_once(): void
    {
        [$settlement, $winner, $seller] = $this->overdue();

        $this->actingAs($seller)->post(route('settlements.cancel', $settlement));
        $this->actingAs($seller)->post(route('settlements.cancel', $settlement));

        $this->assertSame(1, $settlement->events()->where('type', 'cancelled')->count());
        $this->assertSame(1, $winner->notifications()->where('type', SettlementCancelledNotification::class)->count());
    }

    public function test_a_payment_that_wins_the_race_against_a_cancel_stays_paid(): void
    {
        [$settlement, , $seller] = $this->overdue();
        $payment = $this->attempt($settlement);
        Http::fake();

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->actingAs($seller)->post(route('settlements.cancel', $settlement))->assertSessionHasErrors('cancel');

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
    }

    public function test_after_a_cancel_the_winner_cannot_pay_and_late_money_is_held(): void
    {
        [$settlement, $winner, $seller] = $this->overdue();
        $payment = $this->attempt($settlement);
        $http = $this->fakeHttp();

        $this->actingAs($seller)->post(route('settlements.cancel', $settlement));
        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertSessionHasErrors('payment');
        $http->assertNothingSent();

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->assertSame(AuctionSettlement::CANCELLED, $settlement->fresh()->status);
        $this->assertSame('settlement_cancelled', $payment->fresh()->reason);
    }

    public function test_cancel_works_without_a_key_because_it_is_a_seller_decision_not_a_payment(): void
    {
        $this->disablePaystack();
        [$settlement, , $seller] = $this->overdue();

        $this->actingAs($seller)->post(route('settlements.cancel', $settlement))->assertSessionHas('status');

        $this->assertSame(AuctionSettlement::CANCELLED, $settlement->fresh()->status);
    }

    public function test_the_cancelled_page_says_what_happened_to_each_side(): void
    {
        [$settlement, $winner, $seller] = $this->overdue();
        $this->actingAs($seller)->post(route('settlements.cancel', $settlement));

        $this->actingAs($seller)->get(route('settlements.show', $settlement))->assertOk()->assertSee('Cancelled')->assertSee('not been relisted');
        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertOk()->assertSee('Cancelled')->assertDontSee('Pay now');
        $this->assertSame(1, Auction::count());
    }
}
