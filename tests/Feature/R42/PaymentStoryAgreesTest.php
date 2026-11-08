<?php

namespace Tests\Feature\R42;

use App\Models\SettlementPayment;
use App\Models\User;
use App\Support\SettlementPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * Design jury 2, P0 1: the seller's lots list said "Payment overdue", the operator page "Payment failed", while the sale page and the buyer
 * said payment opens soon and nothing is due. Every list now takes its sale label from App\Support\SettlementPosition, and a payment attempt
 * is labelled as history that did not complete.
 */
class PaymentStoryAgreesTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    public function test_the_list_label_is_never_overdue_or_due_while_payment_is_closed(): void
    {
        $this->disablePaystack();

        foreach (['awaiting_payment', 'payment_overdue'] as $status) {
            [$settlement] = $this->settlement(590000, ['status' => $status]);
            $position = SettlementPosition::of($settlement);

            $this->assertSame('Waiting for payment to open', $position->listLabel(false));
            $this->assertSame('Payment opens soon', $position->listLabel(true));
            $this->assertSame('Waiting for payment to open', $position->listLabel(null));
        }
    }

    public function test_the_list_label_with_payment_open(): void
    {
        $this->enablePaystack();

        $this->assertSame('Awaiting payment', SettlementPosition::of($this->settlement(1000, ['status' => 'awaiting_payment'])[0])->listLabel(false));
        $this->assertSame('Buyer is late paying', SettlementPosition::of($this->settlement(1000, ['status' => 'payment_overdue'])[0])->listLabel(false));
        $this->assertSame('Payment overdue', SettlementPosition::of($this->settlement(1000, ['status' => 'payment_overdue'])[0])->listLabel(true));
        $this->assertSame('Paid', SettlementPosition::of($this->settlement(1000, ['status' => 'paid'])[0])->listLabel(false));
        $this->assertSame('Sale cancelled', SettlementPosition::of($this->settlement(1000, ['status' => 'cancelled'])[0])->listLabel(false));
    }

    public function test_the_sellers_lot_list_agrees_with_the_sale_page_while_payment_is_closed(): void
    {
        $this->disablePaystack();
        [$settlement, $winner, $seller] = $this->settlement(590000, ['status' => 'payment_overdue', 'payment_due_at' => now()->subDay()]);

        $list = $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk();
        $list->assertSee('Waiting for payment to open');
        $list->assertDontSee('Payment overdue');
        $list->assertDontSee('Buyer is late');

        $this->actingAs($seller)->get(route('settlements.show', $settlement))->assertOk()
            ->assertSee('Waiting for payment to open')
            ->assertDontSee('Cancel this sale');
    }

    public function test_the_sellers_lot_list_says_the_buyer_is_late_only_when_payment_is_open(): void
    {
        $this->enablePaystack();
        [$settlement, $winner, $seller] = $this->settlement(590000, ['status' => 'payment_overdue', 'payment_due_at' => now()->subDay()]);

        $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->assertSee('Buyer is late paying');
    }

    public function test_a_cancelled_sale_reads_cancelled_in_the_sellers_list_in_both_modes(): void
    {
        foreach ([false, true] as $on) {
            $on ? $this->enablePaystack() : $this->disablePaystack();
            [, , $seller] = $this->settlement(590000, ['status' => 'cancelled', 'cancelled_at' => now()->subDay()]);

            $this->actingAs($seller)->get(route('seller.auctions.index'))->assertOk()->assertSee('Sale cancelled');
        }
    }

    public function test_the_operator_page_labels_attempts_as_history_and_does_not_contradict_the_sale(): void
    {
        $this->disablePaystack();
        [$settlement] = $this->settlement(590000);
        $this->attempt($settlement, ['status' => SettlementPayment::FAILED, 'summary' => 'Card declined.']);
        $this->attempt($settlement, ['status' => SettlementPayment::INITIATED]);
        $operator = User::factory()->withPersonalTeam()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        $page = $this->actingAs($operator)->get(route('operator.settlement-payments.index'))->assertOk();

        $page->assertSee('Payment attempt, not completed', false);
        $page->assertSee('Attempt failed');
        $page->assertSee('Attempt started');
        $page->assertDontSee('Payment failed');
        $page->assertDontSee('Payment started');
        $page->assertSee('Sale: waiting for payment to open');
        $page->assertSee('These rows are earlier records');
    }

    public function test_the_operator_page_does_not_call_the_rows_earlier_records_while_payment_is_open(): void
    {
        $this->enablePaystack();
        [$settlement] = $this->settlement(590000);
        $this->attempt($settlement);
        $operator = User::factory()->withPersonalTeam()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        $this->actingAs($operator)->get(route('operator.settlement-payments.index'))->assertOk()
            ->assertDontSee('These rows are earlier records')
            ->assertSee('Sale: awaiting payment');
    }
}
