<?php

namespace Tests\Feature\Payments;

use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Notifications\SettlementPaidNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class PaymentCallbackTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
    }

    public function test_a_verified_success_marks_the_settlement_paid_and_tells_both_parties(): void
    {
        [$settlement, $winner, $seller] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 123450)]);

        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement))
            ->assertRedirect(route('settlements.show', $settlement))
            ->assertSessionHas('status');

        $http->assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.paystack.co/transaction/verify/'.$payment->reference);
        $settlement->refresh();
        $payment->refresh();
        $this->assertSame(AuctionSettlement::PAID, $settlement->status);
        $this->assertNotNull($settlement->paid_at);
        $this->assertSame(SettlementPayment::PAID, $payment->status);
        $this->assertSame('4099260516', $payment->provider_transaction_id);
        $this->assertNotNull($payment->paid_at);

        $this->assertSame(1, $winner->notifications()->where('type', SettlementPaidNotification::class)->count());
        $sellerNote = $seller->notifications()->where('type', SettlementPaidNotification::class)->sole()->data;
        $this->assertStringContainsString('settlement to you is manual', $sellerNote['message']);
        $this->assertStringContainsString('R1,234.50', $sellerNote['message']);
    }

    public function test_query_parameters_are_never_trusted(): void
    {
        [$settlement, $winner] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 123450, 'abandoned')]);

        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement, false).'?status=success&reference=FORGED-REF&trxref=FORGED-REF&amount=1&paid=1')
            ->assertRedirect(route('settlements.show', $settlement));

        $http->assertSent(fn (Request $r) => str_ends_with($r->url(), '/verify/'.$payment->reference));
        $http->assertSentCount(1);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_the_callback_with_a_forged_success_and_no_attempt_marks_nothing_paid_and_calls_nothing(): void
    {
        [$settlement, $winner] = $this->settlement();
        $http = $this->fakeHttp();

        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement, false).'?status=success&reference=AUC-FORGED')
            ->assertRedirect(route('settlements.show', $settlement));

        $http->assertNothingSent();
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_verification_mismatches_never_mark_paid(): void
    {
        $cases = [
            'amount too low' => fn ($p) => $this->verifyResponse($p->reference, 100, 'success'),
            'amount too high' => fn ($p) => $this->verifyResponse($p->reference, 999999, 'success'),
            'other currency' => fn ($p) => $this->verifyResponse($p->reference, $p->amount_cents, 'success', ['currency' => 'USD']),
            'other reference' => fn ($p) => $this->verifyResponse('AUC-SOMEONE-ELSE', $p->amount_cents, 'success'),
        ];

        foreach ($cases as $label => $response) {
            [$settlement, $winner] = $this->settlement(123450);
            $payment = $this->attempt($settlement);
            $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $response($payment)]);

            $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement))
                ->assertRedirect(route('settlements.show', $settlement));

            $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status, $label);
            $this->assertNull($settlement->fresh()->paid_at, $label);
            $this->assertSame(SettlementPayment::UNMATCHED, $payment->fresh()->status, $label);
            $this->assertNotEmpty($payment->fresh()->reason, $label);
            $this->assertSame(0, $winner->notifications()->where('type', SettlementPaidNotification::class)->count(), $label);
        }
    }

    public function test_a_failed_attempt_is_recorded_and_the_winner_can_try_again(): void
    {
        [$settlement, $winner] = $this->settlement();
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, $payment->amount_cents, 'failed')]);

        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement))
            ->assertRedirect(route('settlements.show', $settlement));

        $this->assertSame(SettlementPayment::FAILED, $payment->fresh()->status);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/again')]);
        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertRedirect('https://checkout.paystack.com/again');
    }

    public function test_a_payment_still_in_progress_changes_nothing_yet(): void
    {
        [$settlement, $winner] = $this->settlement();
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, $payment->amount_cents, 'ongoing')]);

        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement))
            ->assertRedirect(route('settlements.show', $settlement))->assertSessionHas('status');

        $this->assertSame(SettlementPayment::INITIATED, $payment->fresh()->status);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_a_verification_outage_leaves_state_unchanged_and_the_winner_can_check_again(): void
    {
        [$settlement, $winner] = $this->settlement();
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('timeout '.$this->testKey)]);

        $response = $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement));

        $response->assertRedirect(route('settlements.show', $settlement))->assertSessionHasErrors('payment');
        $this->assertSame(SettlementPayment::INITIATED, $payment->fresh()->status);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);

        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, $payment->amount_cents)]);
        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement));
        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
    }

    public function test_visiting_the_callback_twice_pays_once_and_notifies_once(): void
    {
        [$settlement, $winner, $seller] = $this->settlement();
        $payment = $this->attempt($settlement);
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, $payment->amount_cents)]);

        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement));
        $paidAt = $settlement->fresh()->paid_at;
        $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement))->assertRedirect(route('settlements.show', $settlement));

        $http->assertSentCount(1);
        $this->assertEquals($paidAt, $settlement->fresh()->paid_at);
        $this->assertSame(1, $winner->notifications()->count());
        $this->assertSame(1, $seller->notifications()->where('type', SettlementPaidNotification::class)->count());
    }

    public function test_only_the_winner_may_use_the_callback(): void
    {
        [$settlement, , $seller] = $this->settlement();
        $this->attempt($settlement);
        $http = $this->fakeHttp();

        $this->actingAs($seller)->get(route('settlements.payment.callback', $settlement))->assertForbidden();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('settlements.payment.callback', $settlement))->assertNotFound();
        $this->asGuest()->get(route('settlements.payment.callback', $settlement))->assertRedirect('/login');

        $http->assertNothingSent();
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_the_callback_reads_never_writes_a_foreign_attempt(): void
    {
        [$mine, $me] = $this->settlement(50000);
        [$theirs] = $this->settlement(50000);
        $mineAttempt = $this->attempt($mine);
        $theirAttempt = $this->attempt($theirs);
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($mineAttempt->reference, 50000)]);

        $this->actingAs($me)->get(route('settlements.payment.callback', $mine));

        $http->assertSent(fn (Request $r) => str_ends_with($r->url(), $mineAttempt->reference));
        $this->assertSame(AuctionSettlement::PAID, $mine->fresh()->status);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $theirs->fresh()->status);
        $this->assertSame(SettlementPayment::INITIATED, $theirAttempt->fresh()->status);
    }
}
