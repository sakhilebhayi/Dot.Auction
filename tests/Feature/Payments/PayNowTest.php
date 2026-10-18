<?php

namespace Tests\Feature\Payments;

use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Notifications\AuctionSoldNotification;
use App\Notifications\AuctionWonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class PayNowTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_winner_sees_pay_now_with_the_amount_and_a_test_mode_badge(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);

        $this->actingAs($winner)->get(route('settlements.show', $settlement))
            ->assertOk()
            ->assertSee('Pay now')
            ->assertSee('R1,234.50')
            ->assertSee('TEST MODE')
            ->assertSee('No real money moves')
            ->assertSee(route('settlements.pay', $settlement), false);
    }

    public function test_the_seller_sees_the_sale_without_a_pay_button_and_is_told_payout_is_manual(): void
    {
        $this->enablePaystack();
        [$settlement, , $seller] = $this->settlement();

        $this->actingAs($seller)->get(route('settlements.show', $settlement))
            ->assertOk()
            ->assertDontSee('Pay now')
            ->assertSee('Awaiting payment')
            ->assertSee('Payout is not available yet');
    }

    public function test_a_paid_settlement_tells_the_seller_payment_was_received_but_never_that_they_were_paid(): void
    {
        $this->enablePaystack();
        [$settlement, $winner, $seller] = $this->settlement();
        $settlement->update(['status' => AuctionSettlement::PAID, 'paid_at' => now()]);

        $page = $this->actingAs($seller)->get(route('settlements.show', $settlement))->assertOk()
            ->assertSee('payment received, settlement to seller is manual')
            ->assertSee('Payout is not available yet');
        $this->assertStringNotContainsStringIgnoringCase('you have been paid', $page->getContent());
        $this->assertStringNotContainsStringIgnoringCase('payout sent', $page->getContent());

        $this->actingAs($winner)->get(route('settlements.show', $settlement))
            ->assertOk()->assertSee('Payment received')->assertDontSee('Pay now');
    }

    public function test_an_outsider_cannot_even_see_the_settlement_page(): void
    {
        $this->enablePaystack();
        [$settlement] = $this->settlement();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)->get(route('settlements.show', $settlement))->assertNotFound();
        $this->asGuest()->get(route('settlements.show', $settlement))->assertRedirect('/login');
    }

    public function test_the_list_shows_only_my_own_settlements_in_the_right_role(): void
    {
        $this->enablePaystack();
        [$mine, $me] = $this->settlement(50000);
        [$sold, , $seller] = $this->settlement(70000);
        [$other] = $this->settlement(90000);
        $other->auction->update(['title' => 'Somebody Elses Lot']);
        $mine->auction->update(['title' => 'My Won Lot']);

        $this->actingAs($me)->get(route('settlements.index'))->assertOk()
            ->assertSee('My Won Lot')->assertDontSee('Somebody Elses Lot')->assertSee('You won');
        $this->actingAs($seller)->get(route('settlements.index'))->assertOk()
            ->assertSee($sold->auction->title)->assertSee('You sold');
    }

    public function test_pay_now_initializes_exactly_the_documented_payload_and_redirects_to_paystack(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/abc123')]);
        [$settlement, $winner] = $this->settlement(123450);

        $this->actingAs($winner)->post(route('settlements.pay', $settlement))
            ->assertRedirect('https://checkout.paystack.com/abc123');

        $payment = SettlementPayment::sole();
        $this->assertSame($settlement->id, $payment->settlement_id);
        $this->assertSame('paystack', $payment->provider);
        $this->assertSame(123450, $payment->amount_cents);
        $this->assertSame('ZAR', $payment->currency);
        $this->assertSame(SettlementPayment::INITIATED, $payment->status);
        $this->assertSame($winner->id, $payment->initiated_by);
        $this->assertTrue($payment->expires_at->isFuture());
        $this->assertMatchesRegularExpression('/^AUC-[A-Z0-9]{26}$/', $payment->reference);

        $http->assertSent(function (Request $request) use ($settlement, $winner, $payment) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request->header('Authorization') === ['Bearer '.$this->testKey]
                && $request->data() === [
                    'email' => $winner->email,
                    'amount' => 123450,
                    'currency' => 'ZAR',
                    'reference' => $payment->reference,
                    'callback_url' => route('settlements.payment.callback', $settlement),
                    'metadata' => json_encode(['settlement_id' => $settlement->id, 'auction_id' => $settlement->auction_id]),
                ];
        });
        $http->assertSentCount(1);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_the_amount_always_comes_from_the_settlement_never_from_the_request(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);
        [$settlement, $winner] = $this->settlement(123450);

        $this->actingAs($winner)->post(route('settlements.pay', $settlement), [
            'amount' => 1, 'amount_cents' => 1, 'currency' => 'USD', 'email' => 'attacker@example.test',
        ]);

        $http->assertSent(fn (Request $r) => $r->data()['amount'] === 123450
            && $r->data()['currency'] === 'ZAR'
            && $r->data()['email'] === $winner->email);
    }

    public function test_pay_now_twice_reuses_the_live_attempt_instead_of_starting_a_second(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/once')]);
        [$settlement, $winner] = $this->settlement();

        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertRedirect('https://checkout.paystack.com/once');
        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertRedirect('https://checkout.paystack.com/once');

        $http->assertSentCount(1);
        $this->assertSame(1, SettlementPayment::count());
    }

    public function test_an_expired_attempt_is_retired_and_a_fresh_one_started(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/second')]);
        [$settlement, $winner] = $this->settlement();
        $old = $this->attempt($settlement, ['expires_at' => now()->subMinute(), 'authorization_url' => 'https://checkout.paystack.com/first']);

        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertRedirect('https://checkout.paystack.com/second');

        $this->assertSame(SettlementPayment::EXPIRED, $old->fresh()->status);
        $this->assertSame(2, SettlementPayment::count());
        $this->assertSame(1, SettlementPayment::where('status', SettlementPayment::INITIATED)->count());
    }

    public function test_a_paystack_outage_changes_nothing_and_a_retry_works(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement();
        $this->fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('cURL error 28 '.$this->testKey)]);

        $response = $this->actingAs($winner)->from(route('settlements.show', $settlement))->post(route('settlements.pay', $settlement));

        $response->assertRedirect(route('settlements.show', $settlement));
        $response->assertSessionHasErrors('payment');
        $this->assertStringNotContainsString($this->testKey, json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame(0, SettlementPayment::count(), 'a failed start leaves no attempt behind');
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/retry')]);
        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertRedirect('https://checkout.paystack.com/retry');
        $this->assertSame(1, SettlementPayment::count());
    }

    public function test_a_rejection_from_paystack_is_shown_safely_and_changes_nothing(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement();
        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key LEAKED'], 401)]);

        $response = $this->actingAs($winner)->from(route('settlements.show', $settlement))->post(route('settlements.pay', $settlement));

        $response->assertSessionHasErrors('payment');
        $this->assertStringNotContainsString('LEAKED', json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame(0, SettlementPayment::count());
    }

    public function test_only_the_winner_may_pay(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp();
        [$settlement, , $seller] = $this->settlement();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($seller)->post(route('settlements.pay', $settlement))->assertForbidden();
        $this->actingAs($outsider)->post(route('settlements.pay', $settlement))->assertNotFound();
        $this->asGuest()->post(route('settlements.pay', $settlement))->assertRedirect('/login');

        $http->assertNothingSent();
        $this->assertSame(0, SettlementPayment::count());
    }

    public function test_one_winner_cannot_pay_another_winners_settlement(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp();
        [$mine, $me] = $this->settlement();
        [$theirs] = $this->settlement();

        $this->actingAs($me)->post(route('settlements.pay', $theirs))->assertNotFound();
        $this->actingAs($me)->get(route('settlements.payment.callback', $theirs))->assertNotFound();

        $http->assertNothingSent();
    }

    public function test_a_paid_or_cancelled_settlement_cannot_be_paid_again(): void
    {
        $this->enablePaystack();
        $http = $this->fakeHttp();

        foreach ([AuctionSettlement::PAID, AuctionSettlement::CANCELLED] as $status) {
            [$settlement, $winner] = $this->settlement(100000, ['status' => $status]);

            $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertSessionHasErrors('payment');
        }

        $http->assertNothingSent();
        $this->assertSame(0, SettlementPayment::count());
    }

    public function test_an_overdue_settlement_can_still_be_paid_until_the_seller_cancels_it(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/late')]);
        [$settlement, $winner] = $this->settlement(100000, ['status' => AuctionSettlement::PAYMENT_OVERDUE, 'overdue_at' => now()]);

        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertRedirect('https://checkout.paystack.com/late');
    }

    public function test_with_no_key_the_page_is_honest_and_pay_now_does_nothing(): void
    {
        $this->disablePaystack();
        $http = $this->fakeHttp();
        [$settlement, $winner] = $this->settlement();

        $this->actingAs($winner)->get(route('settlements.show', $settlement))
            ->assertOk()
            ->assertDontSee('Pay now')
            ->assertDontSee('TEST MODE')
            ->assertSee('Payment is not available yet')
            ->assertSee('nothing is due');

        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertSessionHasErrors('payment');
        $http->assertNothingSent();
        $this->assertSame(0, SettlementPayment::count());
    }

    public function test_a_refused_live_key_shows_no_pay_button_and_sends_nothing(): void
    {
        $this->enablePaystack($this->fakeLiveKey());
        $http = $this->fakeHttp();
        [$settlement, $winner] = $this->settlement();

        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertOk()
            ->assertDontSee('Pay now')->assertSee('Payment is not available yet');
        $this->actingAs($winner)->post(route('settlements.pay', $settlement))->assertSessionHasErrors('payment');

        $http->assertNothingSent();
    }

    public function test_the_test_mode_badge_goes_away_only_when_live_is_deliberately_allowed(): void
    {
        $this->enablePaystack($this->fakeLiveKey(), ['paystack.allow_live' => true]);
        [$settlement, $winner] = $this->settlement();

        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertOk()
            ->assertSee('Pay now')->assertDontSee('TEST MODE')->assertSee('LIVE MODE');
    }

    public function test_the_auction_page_links_the_winner_to_the_pay_page_when_payments_are_on(): void
    {
        $this->enablePaystack();
        [$settlement, $winner, $seller] = $this->settlement();

        $this->actingAs($winner)->get(route('auctions.show', $settlement->auction))->assertOk()
            ->assertSee(route('settlements.show', $settlement), false)->assertSee('Pay now');
        $this->actingAs($seller)->get(route('auctions.show', $settlement->auction))->assertOk()
            ->assertDontSee('Pay now')->assertSee('Payout is not available yet');
    }

    public function test_the_won_and_sold_notices_stay_honest_with_and_without_payments(): void
    {
        [$settlement, $winner, $seller] = $this->settlement();
        $auction = $settlement->auction;

        $this->disablePaystack();
        $off = (new AuctionWonNotification($auction))->toDatabase($winner);
        $this->assertStringContainsString('not yet available', $off['message']);

        $this->enablePaystack();
        $won = (new AuctionWonNotification($auction))->toDatabase($winner)['message'];
        $sold = (new AuctionSoldNotification($auction, $settlement))->toDatabase($seller)['message'];
        $this->assertStringContainsString('72 hours', $won);
        $this->assertStringNotContainsString('not yet available', $won);
        $this->assertStringContainsString('payout', strtolower($sold));
        $this->assertStringContainsString('manual', strtolower($sold));
    }
}
