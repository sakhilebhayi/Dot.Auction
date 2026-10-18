<?php

namespace Tests\Feature\Payments;

use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class PaystackConfigTest extends TestCase
{
    use BuildsSettlements;

    public function test_with_no_key_payments_are_off_and_nothing_is_flagged(): void
    {
        $this->disablePaystack();

        $config = app(PaystackConfig::class);

        $this->assertFalse($config->isConfigured());
        $this->assertFalse($config->canTakePayments());
        $this->assertFalse($config->isMisconfigured());
        $this->assertNull($config->mode());
    }

    public function test_a_test_key_can_take_payments_and_is_flagged_as_test_mode(): void
    {
        $this->enablePaystack();

        $config = app(PaystackConfig::class);

        $this->assertTrue($config->canTakePayments());
        $this->assertTrue($config->isTestMode());
        $this->assertSame('test', $config->mode());
        $this->assertSame($this->testKey, $config->secretKey());
    }

    public function test_a_live_key_is_refused_unless_live_is_explicitly_allowed(): void
    {
        $this->enablePaystack($this->fakeLiveKey());
        $config = app(PaystackConfig::class);

        $this->assertTrue($config->isConfigured());
        $this->assertTrue($config->isMisconfigured());
        $this->assertFalse($config->canTakePayments());
        $this->assertFalse($config->isTestMode());
        $this->assertStringContainsString('PAYSTACK_ALLOW_LIVE', (string) $config->problem());

        try {
            $config->secretKey();
            $this->fail('A refused live key must not be handed out.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('live_key_refused', $e->reason);
            $this->assertStringNotContainsString($this->fakeLiveKey(), $e->getMessage());
        }
    }

    public function test_a_live_key_is_never_sent_and_the_refusal_is_logged_without_the_key(): void
    {
        $this->enablePaystack($this->fakeLiveKey());
        $http = $this->fakeHttp();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });

        try {
            app(PaystackClient::class)->verifyTransaction('AUC-1');
            $this->fail('Expected a refusal.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('live_key_refused', $e->reason);
        }

        $http->assertNothingSent();
        $this->assertNotEmpty($logged, 'The refusal must be logged.');
        $this->assertStringNotContainsString($this->fakeLiveKey(), implode("\n", $logged));
    }

    public function test_a_live_key_works_only_with_the_second_switch_on(): void
    {
        $this->enablePaystack($this->fakeLiveKey(), ['paystack.allow_live' => true]);
        $config = app(PaystackConfig::class);

        $this->assertTrue($config->canTakePayments());
        $this->assertFalse($config->isTestMode());
        $this->assertSame('live', $config->mode());
        $this->assertSame($this->fakeLiveKey(), $config->secretKey());
    }

    public function test_a_key_with_an_unknown_prefix_is_refused(): void
    {
        foreach (['totally-not-a-key', 'rk_test_restricted_not_secret', 'pk_test_a_public_key_is_not_secret'] as $key) {
            $this->enablePaystack($key);

            $this->assertTrue(app(PaystackConfig::class)->isMisconfigured(), $key);
            $this->assertFalse(app(PaystackConfig::class)->canTakePayments(), $key);
        }
    }

    public function test_a_non_https_base_url_is_refused_and_nothing_is_sent(): void
    {
        $this->enablePaystack(null, ['paystack.base_url' => 'http://api.paystack.co']);
        $http = $this->fakeHttp();

        $this->assertTrue(app(PaystackConfig::class)->isMisconfigured());

        $this->expectException(PaymentGatewayException::class);
        try {
            app(PaystackClient::class)->verifyTransaction('AUC-1');
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_the_payment_window_falls_back_to_72_hours_for_a_blank_or_bad_value(): void
    {
        config(['auction.payment_window_hours' => 0]);

        $this->assertSame(72, app(PaystackConfig::class)->paymentWindowHours());

        config(['auction.payment_window_hours' => 24]);
        $this->assertSame(24, app(PaystackConfig::class)->paymentWindowHours());
    }
}
