<?php

namespace Tests\Feature\Payments;

use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class PaystackClientTest extends TestCase
{
    use BuildsSettlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
    }

    public function test_initialize_sends_exactly_the_documented_payload_with_cents_and_a_bearer_key(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);

        $result = app(PaystackClient::class)->initializeTransaction(
            'winner@example.test', 123450, 'ZAR', 'AUC-01HREF', 'https://auction.test/settlements/x/payment/callback', ['settlement_id' => 'x', 'auction_id' => 7],
        );

        $http->assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request->header('Authorization') === ['Bearer '.$this->testKey]
                && $request->data() === [
                    'email' => 'winner@example.test',
                    'amount' => 123450,
                    'currency' => 'ZAR',
                    'reference' => 'AUC-01HREF',
                    'callback_url' => 'https://auction.test/settlements/x/payment/callback',
                    'metadata' => json_encode(['settlement_id' => 'x', 'auction_id' => 7]),
                ];
        });
        $http->assertSentCount(1);
        $this->assertSame('https://checkout.paystack.com/test-access-code', $result->authorizationUrl);
        $this->assertSame('AUC-01HREF', $result->reference);
        $this->assertSame('test-access-code', $result->accessCode);
    }

    public function test_verify_returns_a_typed_result_in_integer_cents(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse('AUC-01HREF', 123450)]);

        $result = app(PaystackClient::class)->verifyTransaction('AUC-01HREF');

        $http->assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.paystack.co/transaction/verify/AUC-01HREF'
            && $r->header('Authorization') === ['Bearer '.$this->testKey]);
        $this->assertSame('success', $result->status);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame(123450, $result->amountCents);
        $this->assertSame('ZAR', $result->currency);
        $this->assertSame('AUC-01HREF', $result->reference);
        $this->assertSame('4099260516', $result->transactionId);
    }

    public function test_a_non_success_status_is_reported_and_never_treated_as_success(): void
    {
        foreach (['failed', 'abandoned', 'ongoing', 'pending', 'reversed', 'queued'] as $status) {
            $this->fakeHttp(['api.paystack.co/*' => $this->verifyResponse('AUC-1', 100, $status)]);

            $result = app(PaystackClient::class)->verifyTransaction('AUC-1');

            $this->assertSame($status, $result->status);
            $this->assertFalse($result->isSuccessful(), $status);
        }
    }

    public function test_the_reference_is_url_encoded_so_it_cannot_change_the_path(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/*' => $this->verifyResponse('x', 1)]);

        app(PaystackClient::class)->verifyTransaction('../refund?x=1');

        $http->assertSent(fn (Request $r) => ! str_contains($r->url(), '/refund?') && str_starts_with($r->url(), 'https://api.paystack.co/transaction/verify/'));
    }

    public function test_an_outage_becomes_a_safe_temporary_gateway_exception(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('cURL error 28: timed out for https://api.paystack.co with '.$this->testKey)]);

        try {
            app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'AUC-1', 'https://x.test/cb', []);
            $this->fail('expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            $this->assertStringNotContainsString($this->testKey, $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
            $this->assertTrue($e->isTemporary());
        }
    }

    public function test_server_errors_and_rejections_are_exceptions_without_the_response_body(): void
    {
        foreach ([[500, true], [502, true], [429, true], [401, false], [400, false], [404, false]] as [$code, $temporary]) {
            $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key sk_test_leaked_in_body'], $code)]);

            try {
                app(PaystackClient::class)->verifyTransaction('AUC-1');
                $this->fail("expected an exception for HTTP {$code}");
            } catch (PaymentGatewayException $e) {
                $this->assertStringNotContainsString('leaked_in_body', $e->getMessage());
                $this->assertSame($temporary, $e->isTemporary(), "HTTP {$code}");
            }
        }
    }

    public function test_a_redirect_is_not_followed_and_is_an_error(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/*' => Http::response('', 302, ['Location' => 'https://evil.test/steal'])]);

        $this->expectException(PaymentGatewayException::class);
        try {
            app(PaystackClient::class)->verifyTransaction('AUC-1');
        } finally {
            $http->assertSentCount(1);
        }
    }

    public function test_a_response_without_the_expected_fields_is_an_exception(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['nonsense' => 1]], 200)]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'AUC-1', 'https://x.test/cb', []);
    }

    public function test_a_checkout_url_that_is_not_https_is_refused(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => $this->initializeResponse('http://evil.test/pay')]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'AUC-1', 'https://x.test/cb', []);
    }

    public function test_a_non_integer_amount_in_a_verification_is_an_exception_not_a_guess(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => $this->verifyResponse('AUC-1', 1, 'success', ['amount' => '12.5'])]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->verifyTransaction('AUC-1');
    }

    public function test_a_status_false_in_a_200_is_an_exception(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'nope'], 200)]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->verifyTransaction('AUC-1');
    }

    public function test_nothing_is_logged_that_contains_the_key_the_payload_or_the_checkout_link(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('https://checkout.paystack.com/SECRETCODE9')]);
        app(PaystackClient::class)->initializeTransaction('buyer@example.test', 24690, 'ZAR', 'AUC-1', 'https://x.test/cb', ['settlement_id' => 'x']);

        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'bad '.$this->testKey, 'echo' => 'buyer@example.test'], 400)]);
        try {
            app(PaystackClient::class)->verifyTransaction('AUC-1');
        } catch (PaymentGatewayException) {
            // expected
        }

        $log = implode("\n", $logged);
        $this->assertNotEmpty($logged, 'a failure is logged');
        foreach ([$this->testKey, 'SECRETCODE9', 'buyer@example.test', 'checkout.paystack.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }
}
