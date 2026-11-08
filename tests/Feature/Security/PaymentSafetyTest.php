<?php

namespace Tests\Feature\Security;

use App\Models\AuctionSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * Where a person who is paying is sent, and what a forged request can and cannot change about the payment.
 */
class PaymentSafetyTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    public function test_a_forged_host_header_cannot_choose_where_paystack_sends_the_payer_back(): void
    {
        config(['app.url' => 'https://auction.example.test']);
        $this->enablePaystack();
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);
        [$settlement, $winner] = $this->settlement();

        $this->actingAs($winner)
            ->post('http://evil.example/settlements/'.$settlement->id.'/pay', [], ['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])
            ->assertRedirect();

        $http->assertSent(function (Request $request) use ($settlement) {
            $callback = $request->data()['callback_url'] ?? '';

            return str_starts_with($callback, 'http://auction.example.test/settlements/'.$settlement->id.'/payment/callback')
                || str_starts_with($callback, 'https://auction.example.test/settlements/'.$settlement->id.'/payment/callback');
        });
    }

    public function test_only_an_https_checkout_address_from_paystack_is_ever_followed(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('http://evil.example/checkout')]);
        [$settlement, $winner] = $this->settlement();

        $response = $this->actingAs($winner)->post(route('settlements.pay', $settlement));

        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }

    public function test_the_return_page_cannot_be_used_to_mark_a_sale_paid_or_to_redirect(): void
    {
        $this->enablePaystack();
        $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse('AUC-NOPE', 1, 'failed')]);
        [$settlement, $winner] = $this->settlement();
        $this->attempt($settlement, ['reference' => 'AUC-REAL']);

        $r = $this->actingAs($winner)->get(route('settlements.payment.callback', $settlement).'?status=success&reference=AUC-REAL&trxref=AUC-REAL&redirect=https://evil.example');

        $this->assertStringStartsWith(route('settlements.show', $settlement), (string) $r->headers->get('Location'));
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $settlement->fresh()->status);
    }
}
