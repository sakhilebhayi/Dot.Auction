<?php

namespace Tests\Feature\Payments;

use App\Models\AuctionSettlement;
use App\Models\SettlementEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * R-42 UI: the payments pages for the winner and the seller. One layout, the state in words, the one next action, the
 * honest payout copy once, "Pay by" only while payment is on, times in the display zone, the Paystack return states and the history.
 */
class SettlementPagesTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    /**
     * state, payment on, viewer => text that must show, text that must not.
     *
     * @return array<string, array{0: string, 1: bool, 2: string, 3: list<string>, 4: list<string>}>
     */
    public static function matrix(): array
    {
        return [
            'winner awaiting, on' => ['awaiting_payment', true, 'winner', ['Pay to complete your purchase.', 'Pay now', 'Pay by', 'You owe'], ['Cancel this sale', 'Payout']],
            'winner awaiting, off' => ['awaiting_payment', false, 'winner', ['Nothing is due yet.', 'Payment is not available yet'], ['Pay now', 'Pay by']],
            'winner overdue, on' => ['payment_overdue', true, 'winner', ['Payment is overdue.', 'You can still pay until the seller cancels the sale.', 'Pay now', 'Was due'], ['Cancel this sale']],
            'winner paid' => ['paid', true, 'winner', ['Payment received. Thank you.', 'The seller has been told.', 'Reference'], ['Pay now', 'Cancel this sale']],
            'winner cancelled' => ['cancelled', true, 'winner', ['Cancelled by the seller.', 'it can no longer be paid'], ['Pay now', 'Cancel this sale']],
            'seller awaiting, on' => ['awaiting_payment', true, 'seller', ['Waiting for the buyer to pay.', 'Sold for', 'Your payout'], ['Pay now', 'Cancel this sale']],
            'seller awaiting, off' => ['awaiting_payment', false, 'seller', ['Waiting for the buyer to pay.', 'Payment is not available yet'], ['Pay now', 'Pay by', 'Cancel this sale']],
            'seller overdue' => ['payment_overdue', true, 'seller', ['The buyer has not paid on time.', 'Cancel this sale', 'does not relist the auction and does not refund anyone'], ['Pay now']],
            'seller paid' => ['paid', true, 'seller', ['The buyer has paid.', 'settlement to seller is manual', 'not with you'], ['Pay now', 'Cancel this sale']],
            'seller cancelled' => ['cancelled', true, 'seller', ['You cancelled this sale.', 'The auction has not been relisted.'], ['Pay now', 'Cancel this sale', 'Your payout']],
        ];
    }

    /**
     * @param  list<string>  $see
     * @param  list<string>  $dontSee
     */
    #[DataProvider('matrix')]
    public function test_every_state_reads_right_for_each_viewer(string $status, bool $on, string $viewer, array $see, array $dontSee): void
    {
        $on ? $this->enablePaystack() : $this->disablePaystack();
        [$settlement, $winner, $seller] = $this->settlement(123450, ['status' => $status, 'paid_at' => $status === 'paid' ? now() : null, 'cancelled_at' => $status === 'cancelled' ? now() : null]);
        if ($status === 'paid') {
            $this->attempt($settlement, ['reference' => 'AUC-REF-PAID', 'status' => 'paid']);
        }

        $page = $this->actingAs($viewer === 'winner' ? $winner : $seller)->get(route('settlements.show', $settlement))->assertOk();
        $text = html_entity_decode(strip_tags($page->getContent()));

        foreach ($see as $phrase) {
            $this->assertStringContainsString($phrase, $text, "$status as $viewer: should show \"$phrase\"");
        }
        foreach ($dontSee as $phrase) {
            $this->assertStringNotContainsString($phrase, $text, "$status as $viewer: must not show \"$phrase\"");
        }
        $page->assertSeeText('R1,234.50');
        $this->assertStringNotContainsString('UTC', $page->getContent());
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Payment for /', $page->getContent());
        $this->assertStringContainsString('<title>Payment for ', $page->getContent());
    }

    public function test_the_payout_note_is_printed_once_and_only_to_the_seller(): void
    {
        $this->enablePaystack();
        foreach (['awaiting_payment', 'payment_overdue', 'paid'] as $status) {
            [$settlement, $winner, $seller] = $this->settlement(50000, ['status' => $status, 'paid_at' => $status === 'paid' ? now() : null]);

            $sellerText = html_entity_decode(strip_tags($this->actingAs($seller)->get(route('settlements.show', $settlement))->getContent()));
            $this->assertSame(1, substr_count($sellerText, 'Payout is not available yet'), "$status: once");
            $this->assertSame(0, substr_count(html_entity_decode(strip_tags($this->actingAs($winner)->get(route('settlements.show', $settlement))->getContent())), 'Payout'));
        }
    }

    public function test_strangers_get_a_404_guests_go_to_login_and_only_the_right_party_can_act(): void
    {
        $this->enablePaystack();
        [$settlement, $winner, $seller] = $this->settlement(50000, ['status' => 'payment_overdue']);
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->get(route('settlements.show', $settlement))->assertNotFound();
        $this->actingAs($stranger)->post(route('settlements.cancel', $settlement))->assertNotFound();
        $this->actingAs($winner)->post(route('settlements.cancel', $settlement))->assertForbidden();
        $this->actingAs($seller)->post(route('settlements.pay', $settlement))->assertForbidden();
        $this->asGuest()->get(route('settlements.index'))->assertRedirect('/login');
    }

    public function test_the_deadline_shows_in_local_time_with_the_zone_and_only_while_payment_is_on(): void
    {
        [$settlement, $winner] = $this->settlement(50000, ['payment_due_at' => '2026-11-10 07:00:00']);

        $this->enablePaystack();
        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertSee('10 Nov 2026, 09:00 SAST');
        $this->actingAs($winner)->get(route('settlements.index'))->assertSee('Pay by')->assertSee('10 Nov, 09:00');

        $this->disablePaystack();
        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertDontSee('Pay by')->assertDontSee('09:00');
        $this->actingAs($winner)->get(route('settlements.index'))->assertDontSee('Pay by')->assertDontSee('Pay now');
    }

    public function test_the_cancel_sale_button_asks_first_and_only_the_seller_of_an_overdue_sale_has_it(): void
    {
        $this->enablePaystack();
        [$settlement, , $seller] = $this->settlement(50000, ['status' => 'payment_overdue']);

        $html = $this->actingAs($seller)->get(route('settlements.show', $settlement))->getContent();

        $this->assertStringContainsString('Cancel this sale?', $html);
        $this->assertStringContainsString('data-ds-confirm-form', $html);
        $this->assertStringContainsString(route('settlements.cancel', $settlement), $html);

        $this->actingAs($seller)->followingRedirects()->post(route('settlements.cancel', $settlement))
            ->assertSee('The sale is cancelled and the buyer has been told.')->assertSee('You cancelled this sale.')->assertSee('You cancelled the sale.');
    }

    public function test_the_history_lists_what_moved_the_sale_in_plain_words_for_each_side(): void
    {
        $this->enablePaystack();
        [$settlement, $winner, $seller] = $this->settlement(50000, ['status' => 'cancelled', 'cancelled_at' => now()]);
        SettlementEvent::create(['settlement_id' => $settlement->id, 'type' => 'payment_opened', 'note' => 'internal opened note']);
        SettlementEvent::create(['settlement_id' => $settlement->id, 'type' => 'overdue', 'note' => 'internal overdue note 2026-11-01T00:00:00+00:00']);
        SettlementEvent::create(['settlement_id' => $settlement->id, 'user_id' => $seller->id, 'type' => 'cancelled', 'note' => 'internal cancelled note']);

        $forSeller = $this->actingAs($seller)->get(route('settlements.show', $settlement))->assertOk()
            ->assertSee('Sale recorded at R500.00.')->assertSee('Payment opened for the buyer.')
            ->assertSee('The payment deadline passed and the sale was marked overdue.')->assertSee('You cancelled the sale.');
        $this->assertStringNotContainsString('internal', $forSeller->getContent(), 'the audit notes are not printed');

        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertSee('The seller cancelled the sale.');
    }

    public function test_the_index_filters_by_role_counts_them_and_offers_pay_now_only_where_it_works(): void
    {
        $this->enablePaystack();
        [$bought, $me] = $this->settlement(50000);
        $bought->auction->update(['title' => 'Bought Thing']);
        [$sold] = $this->settlement(70000, [], null);
        $sold->forceFill(['seller_id' => $me->id])->save();
        $sold->auction->update(['title' => 'Sold Thing', 'seller_id' => $me->id]);
        [$paid] = $this->settlement(30000, ['status' => 'paid', 'paid_at' => now()], $me);
        $paid->auction->update(['title' => 'Paid Thing']);

        $all = $this->actingAs($me)->get(route('settlements.index'))->assertOk();
        $this->assertSame(['all' => 3, 'buying' => 2, 'selling' => 1], $all->viewData('counts'));
        $this->assertSame(1, $all->viewData('toPay'));
        $all->assertSee('1 payment to make')->assertSee('Bought Thing')->assertSee('Sold Thing')->assertSee('Paid Thing');
        $this->assertSame(1, substr_count($all->getContent(), 'action="'.route('settlements.pay', $bought).'"'));
        $this->assertStringNotContainsString(route('settlements.pay', $paid), $all->getContent());
        $this->assertSame(1, substr_count(strip_tags($all->getContent()), 'paying you out is a manual step'));

        $this->actingAs($me)->get(route('settlements.index', ['role' => 'selling']))->assertSee('Sold Thing')->assertDontSee('Bought Thing');
        $this->actingAs($me)->get(route('settlements.index', ['role' => 'buying']))->assertSee('Bought Thing')->assertDontSee('Sold Thing');
    }

    public function test_the_empty_index_points_to_browse(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('settlements.index'))
            ->assertOk()->assertSee('No payments yet')->assertSee(route('auctions.index'), false);
    }

    public function test_the_word_payments_is_used_for_people_and_payment_operations_for_the_operator(): void
    {
        [$settlement, $winner] = $this->settlement(50000);

        $html = $this->actingAs($winner)->get(route('settlements.index'))->getContent();
        $this->assertStringContainsString('<title>Payments | ', $html);
        $this->assertStringNotContainsStringIgnoringCase('settlement', strip_tags($html));
        $this->assertStringNotContainsStringIgnoringCase('settlement', strip_tags($this->actingAs($winner)->get(route('settlements.show', $settlement))->getContent()));
    }

    // ------------------------------------------------------------ Paystack return states

    public function test_a_confirmed_payment_comes_back_as_a_titled_success(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 123450)]);

        $this->actingAs($winner)->followingRedirects()->get(route('settlements.payment.callback', $settlement))
            ->assertSee('Payment received')->assertSee('Payment received. Thank you.')->assertDontSee('Pay now')->assertSee($payment->reference);
        $this->assertSame(AuctionSettlement::PAID, $settlement->fresh()->status);
    }

    public function test_a_payment_still_in_progress_says_it_is_being_confirmed(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 123450, 'pending')]);

        $this->actingAs($winner)->followingRedirects()->get(route('settlements.payment.callback', $settlement))
            ->assertSee('Payment is being confirmed')->assertSee('still being processed by Paystack')->assertSee('Pay now');
    }

    public function test_a_failed_payment_says_nothing_was_charged_and_offers_another_try(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 123450, 'failed')]);

        $this->actingAs($winner)->followingRedirects()->get(route('settlements.payment.callback', $settlement))
            ->assertSee('The payment did not go through')->assertSee('you were not charged')->assertSee('Pay now');
    }

    public function test_an_unreachable_paystack_when_confirming_changes_nothing_and_says_how_to_check_again(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);
        $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => fn () => throw new ConnectionException('down')]);

        $this->actingAs($winner)->followingRedirects()->get(route('settlements.payment.callback', $settlement))
            ->assertSee('We could not confirm your payment yet')->assertSee('Nothing has changed on this sale')->assertSee('Pay now');
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_an_unreachable_paystack_when_starting_says_nothing_was_charged(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => fn () => throw new ConnectionException('down')]);

        $this->actingAs($winner)->followingRedirects()->post(route('settlements.pay', $settlement))
            ->assertSee('Payment could not be started')->assertSee('Nothing was charged')->assertSee('Pay now');
    }

    public function test_a_payment_that_matched_no_sale_is_passed_to_an_operator_and_says_so(): void
    {
        $this->enablePaystack();
        [$settlement, $winner] = $this->settlement(123450);
        $payment = $this->attempt($settlement);
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse($payment->reference, 999)]);

        $this->actingAs($winner)->followingRedirects()->get(route('settlements.payment.callback', $settlement))
            ->assertSee('The payment needs a person to look at it')->assertSee('passed to an operator');
    }
}
