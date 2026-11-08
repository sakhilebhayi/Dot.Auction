<?php

namespace Tests\Feature\R42;

use App\Providers\AppServiceProvider;
use App\Services\Paystack\E2eFakePaystackClient;
use App\Services\Paystack\PaystackClient;
use Tests\TestCase;

/** The fake gateway the real-browser suite uses must be impossible to switch on in production. */
class E2eFakePaystackGuardTest extends TestCase
{
    private function resolveWith(string $environment, bool $flag): PaystackClient
    {
        $this->app['env'] = $environment;
        config(['paystack.e2e_fake' => $flag]);
        $this->app->bind(PaystackClient::class, PaystackClient::class);
        $this->app->forgetInstance(PaystackClient::class);
        (new AppServiceProvider($this->app))->register();

        return $this->app->make(PaystackClient::class);
    }

    public function test_the_real_client_is_used_by_default(): void
    {
        $this->assertNotInstanceOf(E2eFakePaystackClient::class, $this->resolveWith('testing', false));
    }

    public function test_the_flag_alone_is_not_enough_in_production(): void
    {
        foreach (['production', 'staging'] as $env) {
            $this->assertNotInstanceOf(E2eFakePaystackClient::class, $this->resolveWith($env, true), "environment {$env}");
        }
    }

    public function test_the_fake_is_bound_only_in_local_or_testing_with_the_flag_on(): void
    {
        foreach (['local', 'testing'] as $env) {
            $this->assertInstanceOf(E2eFakePaystackClient::class, $this->resolveWith($env, true), "environment {$env}");
        }
    }

    public function test_the_fake_checkout_routes_do_not_exist_when_the_flag_is_off(): void
    {
        $this->assertFalse(config('paystack.e2e_fake'), 'the phpunit environment must not enable the fake gateway');
        $this->get('/dev/fake-paystack-mode/ok')->assertNotFound();
    }
}
