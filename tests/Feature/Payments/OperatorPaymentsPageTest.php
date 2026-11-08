<?php

namespace Tests\Feature\Payments;

use App\Models\SettlementPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * R-42 UI: payment operations on the kit. Attention first, filters with counts, a keyboard-reachable scroll region,
 * helpers for amounts and times, an empty state, and access for operators only.
 */
class OperatorPaymentsPageTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->withPersonalTeam()->platformOperator()->create();
    }

    private function ghost(string $reference, array $extra = []): SettlementPayment
    {
        return SettlementPayment::forceCreate(array_merge(['provider' => 'paystack', 'reference' => $reference, 'status' => SettlementPayment::UNMATCHED, 'reason' => 'unknown_reference', 'amount_cents' => 5000, 'currency' => 'ZAR'], $extra));
    }

    public function test_money_that_needs_a_person_is_listed_first_whatever_the_order_it_arrived_in(): void
    {
        [$settlement] = $this->settlement(123450);
        $this->ghost('GHOST-OLD');
        $this->attempt($settlement, ['reference' => 'FINE-NEWER', 'status' => SettlementPayment::PAID, 'paid_at' => now()]);
        $this->ghost('GHOST-NEWEST');
        $this->attempt($settlement, ['reference' => 'FINE-NEWEST']);

        $html = $this->actingAs($this->operator())->get(route('operator.settlement-payments.index'))->assertOk()->getContent();

        $order = collect(['GHOST-NEWEST', 'GHOST-OLD', 'FINE-NEWEST', 'FINE-NEWER'])->map(fn ($ref) => strpos($html, $ref));
        $this->assertSame($order->sort()->values()->all(), $order->values()->all(), 'attention first, then newest first');
    }

    public function test_filters_have_real_counts_and_each_shows_only_its_status(): void
    {
        [$settlement] = $this->settlement(123450);
        $this->ghost('GHOST-1');
        $this->attempt($settlement, ['reference' => 'PAID-1', 'status' => SettlementPayment::PAID, 'paid_at' => now()]);
        $this->attempt($settlement, ['reference' => 'PAID-2', 'status' => SettlementPayment::PAID, 'paid_at' => now()]);
        $this->attempt($settlement, ['reference' => 'FAILED-1', 'status' => SettlementPayment::FAILED]);
        $op = $this->operator();

        $all = $this->actingAs($op)->get(route('operator.settlement-payments.index'))->assertOk();
        $this->assertEquals(['unmatched' => 1, 'paid' => 2, 'failed' => 1], $all->viewData('counts')->all());
        $this->assertSame(4, $all->viewData('allCount'));

        $this->actingAs($op)->get(route('operator.settlement-payments.index', ['status' => 'paid']))
            ->assertSee('PAID-1')->assertSee('PAID-2')->assertDontSee('FAILED-1')->assertDontSee('GHOST-1');
        $this->actingAs($op)->get(route('operator.settlement-payments.index', ['status' => 'failed']))->assertSee('FAILED-1')->assertDontSee('PAID-1');
        // The first filter's name keeps working.
        $this->actingAs($op)->get(route('operator.settlement-payments.index', ['show' => 'attention']))->assertSee('GHOST-1')->assertDontSee('PAID-1');
        // A made-up status is ignored, not an error.
        $this->actingAs($op)->get(route('operator.settlement-payments.index', ['status' => 'bogus']))->assertOk()->assertSee('PAID-1');
    }

    public function test_the_table_is_a_keyboard_reachable_region_with_helpers_for_money_and_time(): void
    {
        [$settlement] = $this->settlement(123450);
        $this->attempt($settlement, ['reference' => 'AUC-ONE', 'amount_cents' => 123450]);
        SettlementPayment::query()->update(['created_at' => '2026-11-10 07:00:00']);

        $page = $this->actingAs($this->operator())->get(route('operator.settlement-payments.index'))->assertOk();
        $html = $page->getContent();

        $this->assertMatchesRegularExpression('/<div class="ops-region" role="region" tabindex="0" aria-label="[^"]+"/', $html);
        $this->assertMatchesRegularExpression('/ds-table\s+is-stackable/', $html, 'a phone gets a card per payment');
        $page->assertSeeText('R1,234.50')->assertSee('10 Nov 2026, 09:00 SAST');
        $this->assertStringContainsString('<title>Payment operations | ', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Payment operations\s*<\/h1>/', $html);
        $this->assertStringNotContainsString('UTC', $html);
    }

    public function test_the_empty_state_and_the_nothing_needs_attention_state(): void
    {
        $op = $this->operator();
        $this->actingAs($op)->get(route('operator.settlement-payments.index'))->assertOk()
            ->assertSee('No payments have been recorded yet')->assertSee('No payments need a person');

        [$settlement] = $this->settlement(100);
        $this->attempt($settlement, ['status' => SettlementPayment::PAID, 'paid_at' => now()]);
        $this->actingAs($op)->get(route('operator.settlement-payments.index', ['status' => 'unmatched']))->assertOk()
            ->assertSee('Nothing needs attention')->assertSee('Show all payments');
    }

    public function test_configuration_problems_are_stated_to_the_operator(): void
    {
        $op = $this->operator();
        $this->disablePaystack();
        $this->actingAs($op)->get(route('operator.settlement-payments.index'))->assertSee('Online payment is not switched on');

        $this->enablePaystack($this->fakeLiveKey());
        $this->actingAs($op)->get(route('operator.settlement-payments.index'))->assertSee('Payments are switched off')->assertSee('live Paystack key');
    }

    public function test_only_operators_get_in(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('operator.settlement-payments.index'))->assertForbidden();
        $this->asGuest()->get(route('operator.settlement-payments.index'))->assertRedirect('/login');

        $html = $this->actingAs(User::factory()->withPersonalTeam()->platformOperator()->create())->get(route('operator.settlement-payments.index'))->getContent();
        $this->assertStringContainsString('Payment operations', $html);
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('settlements.index'))->assertDontSee('Payment operations');
    }
}
