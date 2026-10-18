<?php

namespace Tests\Feature\Payments;

use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Notifications\SettlementPaidNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        Http::fake();
    }

    private function scene(int $cents = 123450): array
    {
        [$settlement, $winner, $seller] = $this->settlement($cents);

        return [$settlement, $this->attempt($settlement), $winner, $seller];
    }

    // ---- signature -------------------------------------------------------------------------

    public function test_a_valid_signature_over_the_raw_body_marks_the_settlement_paid_without_calling_paystack(): void
    {
        [$settlement, $payment, $winner, $seller] = $this->scene();

        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $settlement->refresh();
        $this->assertSame(AuctionSettlement::PAID, $settlement->status);
        $this->assertSame(SettlementPayment::PAID, $payment->fresh()->status);
        $this->assertSame('4099260516', $payment->fresh()->provider_transaction_id);
        $this->assertSame(1, $winner->notifications()->where('type', SettlementPaidNotification::class)->count());
        $this->assertSame(1, $seller->notifications()->where('type', SettlementPaidNotification::class)->count());
        Http::assertNothingSent();
    }

    public function test_the_signature_matrix_rejects_everything_but_the_exact_hmac_of_the_exact_bytes(): void
    {
        [$settlement, $payment] = $this->scene();
        $raw = json_encode($this->chargeSuccess($payment));
        $valid = $this->sign($raw);

        $bad = [
            'missing' => [null, ['HTTP_X_PAYSTACK_SIGNATURE' => '']],
            'wrong key' => [$this->sign($raw, 'sk_test_some_other_key'), []],
            'signed a different body' => [$this->sign($raw.' '), []],
            'truncated' => [substr($valid, 0, 64), []],
            'extended' => [$valid.'00', []],
            'sha256 instead of sha512' => [hash_hmac('sha256', $raw, $this->testKey), []],
            'plain sha512 of the body' => [hash('sha512', $raw), []],
            'garbage' => ['not-a-signature', []],
            'whitespace around a valid one is not forgiven by the body' => [$this->sign(' '.$raw), []],
        ];

        foreach ($bad as $label => [$signature, $server]) {
            $response = $this->webhook($raw, $signature ?? '', $server);

            $response->assertStatus(401);
            $this->assertStringNotContainsString('signature', strtolower($response->getContent()), $label);
            $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status, $label);
            $this->assertSame(SettlementPayment::INITIATED, $payment->fresh()->status, $label);
        }
    }

    public function test_a_call_with_no_signature_header_at_all_is_rejected(): void
    {
        [, $payment] = $this->scene();

        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->chargeSuccess($payment)))
            ->assertStatus(401);
    }

    public function test_the_signature_is_checked_before_the_body_is_read_so_junk_gets_401_not_400(): void
    {
        $this->webhook('{{{not json', 'deadbeef')->assertStatus(401);
        $this->webhook('', 'deadbeef')->assertStatus(401);
    }

    public function test_an_uppercase_hex_signature_still_verifies(): void
    {
        [$settlement, $payment] = $this->scene();
        $raw = json_encode($this->chargeSuccess($payment));

        $this->webhook($raw, strtoupper($this->sign($raw)))->assertOk();

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
    }

    public function test_with_no_key_or_a_refused_live_key_nothing_can_be_verified_so_everything_is_401(): void
    {
        [$settlement, $payment] = $this->scene();
        $raw = json_encode($this->chargeSuccess($payment));

        $this->disablePaystack();
        $this->webhook($raw, $this->sign($raw, ''))->assertStatus(401);

        $live = $this->fakeLiveKey();
        $this->enablePaystack($live);
        $this->webhook($raw, $this->sign($raw, $live))->assertStatus(401);

        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_the_endpoint_needs_no_csrf_token_and_starts_no_session(): void
    {
        [, $payment] = $this->scene();

        $response = $this->webhook($this->chargeSuccess($payment));

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies(), 'no session or other cookie for a server-to-server call');
    }

    public function test_the_endpoint_is_throttled(): void
    {
        config(['paystack.webhook_attempts_per_minute' => 3]);
        RateLimiter::clear('paystack-webhook');

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->webhook('{}', 'bad')->status();
        }

        $this->assertSame([401, 401, 401, 429, 429], $statuses);
    }

    // ---- body ------------------------------------------------------------------------------

    public function test_a_validly_signed_body_that_is_not_json_is_a_400(): void
    {
        $this->webhook('{{{not json')->assertStatus(400);
        $this->webhook('"just a string"')->assertStatus(400);
        $this->webhook('12345')->assertStatus(400);
        $this->webhook('')->assertStatus(400);
    }

    public function test_other_events_are_acknowledged_and_ignored(): void
    {
        [$settlement, $payment] = $this->scene();

        foreach (['charge.failed', 'transfer.success', 'refund.processed', 'customeridentification.success'] as $event) {
            $this->webhook($this->chargeSuccess($payment, [], $event))->assertOk();
        }
        $this->webhook(['data' => ['nothing' => true]])->assertOk();

        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
        $this->assertSame(1, SettlementPayment::count());
    }

    // ---- matching --------------------------------------------------------------------------

    public function test_an_unknown_reference_is_recorded_as_unmatched_for_an_operator_and_acknowledged(): void
    {
        [$settlement] = $this->scene();

        $this->webhook(['event' => 'charge.success', 'data' => [
            'id' => 777, 'status' => 'success', 'reference' => 'SOMETHING-ELSE-1', 'amount' => 5000, 'currency' => 'ZAR',
        ]])->assertOk();

        $row = SettlementPayment::where('reference', 'SOMETHING-ELSE-1')->sole();
        $this->assertNull($row->settlement_id);
        $this->assertSame(SettlementPayment::UNMATCHED, $row->status);
        $this->assertSame('unknown_reference', $row->reason);
        $this->assertSame(5000, $row->amount_cents);
        $this->assertSame('777', $row->provider_transaction_id);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_an_unknown_reference_replayed_does_not_pile_up_records(): void
    {
        $body = ['event' => 'charge.success', 'data' => ['id' => 778, 'status' => 'success', 'reference' => 'GHOST-1', 'amount' => 5000, 'currency' => 'ZAR']];

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();

        $this->assertSame(1, SettlementPayment::where('reference', 'GHOST-1')->count());
    }

    public function test_a_success_with_no_usable_reference_is_acknowledged_and_kept_for_an_operator(): void
    {
        $this->webhook(['event' => 'charge.success', 'data' => ['id' => 1, 'status' => 'success', 'amount' => 5000]])->assertOk();

        $row = SettlementPayment::sole();
        $this->assertSame(SettlementPayment::UNMATCHED, $row->status);
        $this->assertStringStartsWith('unreferenced-', $row->reference);
    }

    public function test_amount_currency_status_and_metadata_mismatches_are_held_never_applied(): void
    {
        $cases = [
            'amount_mismatch' => ['amount' => 100],
            'currency_mismatch' => ['currency' => 'USD'],
            'not_successful' => ['status' => 'failed'],
            'metadata_mismatch' => ['metadata' => ['settlement_id' => '00000000-0000-0000-0000-000000000000']],
            'malformed_amount' => ['amount' => '123450'],
        ];

        foreach ($cases as $reason => $override) {
            [$settlement, $payment, $winner] = $this->scene(123450);

            $this->webhook($this->chargeSuccess($payment, $override))->assertOk();

            $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status, $reason);
            $this->assertSame(SettlementPayment::UNMATCHED, $payment->fresh()->status, $reason);
            $this->assertSame($reason, $payment->fresh()->reason, $reason);
            $this->assertSame(0, $winner->notifications()->count(), $reason);
        }
    }

    public function test_a_whole_number_float_amount_is_accepted_as_cents(): void
    {
        [$settlement, $payment] = $this->scene(123450);
        $raw = str_replace('"amount":123450', '"amount":123450.0', json_encode($this->chargeSuccess($payment)));
        $this->assertStringContainsString('123450.0', $raw);

        $this->webhook($raw)->assertOk();

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
    }

    public function test_the_settlement_amount_is_the_authority_even_if_the_attempt_row_disagrees(): void
    {
        [$settlement, $payment] = $this->scene(123450);
        $payment->update(['amount_cents' => 100]);

        $this->webhook($this->chargeSuccess($payment, ['amount' => 100]))->assertOk();

        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    // ---- replay and races ------------------------------------------------------------------

    public function test_a_replayed_webhook_pays_once_and_notifies_once(): void
    {
        [$settlement, $payment, $winner, $seller] = $this->scene();
        $body = $this->chargeSuccess($payment);

        $this->webhook($body)->assertOk();
        $paidAt = $settlement->fresh()->paid_at;
        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();

        $this->assertEquals($paidAt, $settlement->fresh()->paid_at);
        $this->assertSame(1, SettlementPayment::count());
        $this->assertSame(1, $winner->notifications()->count());
        $this->assertSame(1, $seller->notifications()->where('type', SettlementPaidNotification::class)->count());
        $this->assertSame(1, $settlement->events()->where('type', 'paid')->count());
    }

    public function test_callback_then_webhook_and_webhook_then_callback_both_end_paid_once(): void
    {
        foreach (['callback first' => 7001, 'webhook first' => 7002] as $order => $transactionId) {
            [$settlement, $payment, $winner] = $this->scene();
            $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, $payment->amount_cents, 'success', ['id' => $transactionId])]);
            $callback = fn () => $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement));
            $hook = fn () => $this->webhook($this->chargeSuccess($payment, ['id' => $transactionId]))->assertOk();

            $order === 'callback first' ? [$callback(), $hook()] : [$hook(), $callback()];

            $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status, $order);
            $this->assertSame(1, SettlementPayment::where('settlement_id', $settlement->id)->count(), $order);
            $this->assertSame(1, $winner->notifications()->where('type', SettlementPaidNotification::class)->count(), $order);
        }
    }

    public function test_a_stale_copy_of_the_settlement_cannot_pay_twice(): void
    {
        [$settlement, $payment, $winner] = $this->scene();
        $stale = AuctionSettlement::find($settlement->id);

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $stale->status, 'the stale copy still believes it is unpaid');

        $second = $this->attempt($settlement, ['reference' => 'AUC-SECOND']);
        $this->webhook($this->chargeSuccess($second, ['id' => 555]))->assertOk();

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(SettlementPayment::UNMATCHED, $second->fresh()->status);
        $this->assertSame('duplicate_payment', $second->fresh()->reason);
        $this->assertSame(1, $winner->notifications()->where('type', SettlementPaidNotification::class)->count());
    }

    public function test_the_same_provider_transaction_cannot_settle_two_references(): void
    {
        [$settlementA, $paymentA] = $this->scene(50000);
        [$settlementB, $paymentB] = $this->scene(50000);

        $this->webhook($this->chargeSuccess($paymentA, ['id' => 9001]))->assertOk();
        $this->webhook($this->chargeSuccess($paymentB, ['id' => 9001]))->assertOk();

        $this->assertSame(AuctionSettlement::PAID, $settlementA->fresh()->status);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlementB->fresh()->status);
        $this->assertSame('duplicate_transaction', $paymentB->fresh()->reason);
        $this->assertSame(1, SettlementPayment::whereNotNull('provider_transaction_id')->where('provider_transaction_id', '9001')->count());
    }

    public function test_a_late_payment_on_an_expired_attempt_or_an_overdue_settlement_is_still_applied(): void
    {
        [$settlement, $payment] = $this->scene();
        $payment->update(['status' => SettlementPayment::EXPIRED, 'expires_at' => now()->subHour()]);
        $settlement->update(['status' => AuctionSettlement::PAYMENT_OVERDUE, 'overdue_at' => now()->subDay()]);

        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
    }

    public function test_money_arriving_for_a_cancelled_settlement_is_held_for_an_operator(): void
    {
        [$settlement, $payment, $winner] = $this->scene();
        $settlement->update(['status' => AuctionSettlement::CANCELLED, 'cancelled_at' => now()]);

        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame(AuctionSettlement::CANCELLED, $settlement->fresh()->status);
        $this->assertSame(SettlementPayment::UNMATCHED, $payment->fresh()->status);
        $this->assertSame('settlement_cancelled', $payment->fresh()->reason);
        $this->assertSame(0, $winner->notifications()->count());
    }

    public function test_a_payment_failure_event_never_changes_a_paid_settlement(): void
    {
        [$settlement, $payment] = $this->scene();
        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->webhook($this->chargeSuccess($payment, ['status' => 'failed']))->assertOk();

        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(SettlementPayment::PAID, $payment->fresh()->status);
    }
}
