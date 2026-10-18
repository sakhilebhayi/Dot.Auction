<?php

namespace Tests\Feature\Payments;

use App\Models\SettlementPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class OperatorSettlementPaymentsTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
    }

    private function operator(): User
    {
        return User::firstWhere('email', 'ops@example.test')
            ?? User::factory()->withPersonalTeam()->platformOperator()->create(['email' => 'ops@example.test']);
    }

    public function test_operators_see_every_payment_with_the_ones_needing_attention_called_out(): void
    {
        [$settlement] = $this->settlement(123450);
        $settlement->auction->update(['title' => 'Vintage Brass Compass']);
        $this->attempt($settlement, ['reference' => 'AUC-ATTEMPT-INITIATED']);
        $this->attempt($settlement, ['reference' => 'AUC-ATTEMPT-PAID', 'status' => SettlementPayment::PAID, 'provider_transaction_id' => '55', 'paid_at' => now()]);
        $this->attempt($settlement, ['reference' => 'AUC-ATTEMPT-HELD', 'status' => SettlementPayment::UNMATCHED, 'reason' => 'amount_mismatch', 'summary' => 'Paystack reported 100 cents; the sale is 123450.']);
        SettlementPayment::create(['provider' => 'paystack', 'reference' => 'GHOST-REF-1', 'status' => SettlementPayment::UNMATCHED, 'reason' => 'unknown_reference', 'amount_cents' => 5000, 'currency' => 'ZAR']);

        $page = $this->actingAs($this->operator())->get(route('operator.settlement-payments.index'))->assertOk()
            ->assertSee('2 payments need an operator')
            ->assertSee('AUC-ATTEMPT-INITIATED')->assertSee('AUC-ATTEMPT-PAID')->assertSee('AUC-ATTEMPT-HELD')->assertSee('GHOST-REF-1')
            ->assertSee('Vintage Brass Compass')
            ->assertSee('Amount mismatch')->assertSee('Unknown reference')
            ->assertSee('R1,234.50')->assertSee('R50.00')
            ->assertSee('TEST MODE');

        $this->assertStringNotContainsString('<form', substr($page->getContent(), strpos($page->getContent(), '<main')));
    }

    public function test_with_nothing_wrong_the_page_says_so(): void
    {
        $this->actingAs($this->operator())->get(route('operator.settlement-payments.index'))
            ->assertOk()->assertSee('No payments need an operator')->assertSee('No payments have been recorded yet');
    }

    public function test_the_attention_filter_hides_the_fine_ones(): void
    {
        [$settlement] = $this->settlement();
        $this->attempt($settlement, ['reference' => 'AUC-FINE-ONE', 'status' => SettlementPayment::PAID, 'provider_transaction_id' => '9']);
        $this->attempt($settlement, ['reference' => 'AUC-TROUBLE-ONE', 'status' => SettlementPayment::UNMATCHED, 'reason' => 'duplicate_payment']);

        $this->actingAs($this->operator())->get(route('operator.settlement-payments.index', ['show' => 'attention']))
            ->assertOk()->assertSee('AUC-TROUBLE-ONE')->assertDontSee('AUC-FINE-ONE');
    }

    public function test_only_operators_may_see_it_and_it_is_read_only(): void
    {
        $this->get(route('operator.settlement-payments.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('operator.settlement-payments.index'))->assertForbidden();

        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'operator.') && array_diff($r->methods(), ['GET', 'HEAD']));
        $this->assertCount(0, $writes);
    }

    public function test_the_page_never_prints_checkout_links_or_secrets(): void
    {
        [$settlement] = $this->settlement();
        $this->attempt($settlement, ['authorization_url' => 'https://checkout.paystack.com/SECRETLINK']);
        Http::fake();

        $page = $this->actingAs($this->operator())->get(route('operator.settlement-payments.index'))->assertOk();

        $this->assertStringNotContainsString('SECRETLINK', $page->getContent());
        $this->assertStringNotContainsString($this->testKey, $page->getContent());
    }
}
