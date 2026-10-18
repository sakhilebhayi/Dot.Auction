<?php

namespace Tests\Concerns;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for the Paystack settlement tests. Nothing here talks to the network: every request goes
 * through fakeHttp(), which also refuses any request that was not given an answer, so a test that
 * forgot to fake an endpoint fails instead of calling Paystack. Every key is a made-up test value.
 */
trait BuildsSettlements
{
    protected string $testKey = 'sk_test_unit_test_key_0123456789';

    /**
     * A made-up live-prefixed key, assembled at run time so no secret scanner can mistake the source
     * for a real key.
     */
    protected function fakeLiveKey(): string
    {
        return 'sk_'.'live_'.str_repeat('0', 30).'fake9';
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function enablePaystack(?string $key = null, array $extra = []): void
    {
        config(array_merge([
            'paystack.secret_key' => $key ?? $this->testKey,
            'paystack.public_key' => 'pk_test_unit_public_0123456789',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.allow_live' => false,
            'paystack.attempt_ttl_minutes' => 30,
            'auction.payment_window_hours' => 72,
        ], $extra));
    }

    /** Forget whoever actingAs() signed in, so the next request is anonymous. */
    protected function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function disablePaystack(): void
    {
        config(['paystack.secret_key' => '', 'paystack.public_key' => '', 'paystack.allow_live' => false]);
    }

    /**
     * @param  array<string, mixed>  $routes  URL pattern => response / callback / sequence
     */
    protected function fakeHttp(array $routes = []): Factory
    {
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake($routes);
        Http::swap($factory);

        return $factory;
    }

    protected function initializeResponse(string $url = 'https://checkout.paystack.com/test-access-code'): callable
    {
        return function ($request) use ($url) {
            return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                'authorization_url' => $url,
                'access_code' => 'test-access-code',
                'reference' => $request->data()['reference'],
            ]], 200);
        };
    }

    /**
     * @param  array<string, mixed>  $overrides  merged over `data`
     */
    protected function verifyResponse(string $reference, int $amountCents, string $status = 'success', array $overrides = []): PromiseInterface
    {
        return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => array_merge([
            'id' => 4099260516,
            'status' => $status,
            'reference' => $reference,
            'amount' => $amountCents,
            'currency' => 'ZAR',
            'paid_at' => '2026-10-18T12:00:00.000Z',
            'gateway_response' => 'Successful',
            'channel' => 'card',
        ], $overrides)], 200);
    }

    /**
     * A sold auction: a winning bid, and an awaiting_payment settlement for it.
     *
     * @param  array<string, mixed>  $settlement  overrides for the settlement row
     * @return array{0: AuctionSettlement, 1: User, 2: User} settlement, winner, seller
     */
    protected function settlement(int $amountCents = 123450, array $settlement = [], ?User $winner = null): array
    {
        $winner ??= User::factory()->withPersonalTeam()->create();
        $auction = Auction::factory()->create([
            'status' => 'ended',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subMinute(),
            'current_price' => Auction::fromCents($amountCents),
        ]);
        $bid = Bid::create([
            'auction_id' => $auction->id, 'bidder_id' => $winner->id,
            'amount' => Auction::fromCents($amountCents), 'is_winning' => true,
        ]);

        $row = AuctionSettlement::create(array_merge([
            'auction_id' => $auction->id,
            'winning_bid_id' => $bid->id,
            'seller_id' => $auction->seller_id,
            'winner_id' => $winner->id,
            'amount_cents' => $amountCents,
            'currency' => 'ZAR',
            'status' => AuctionSettlement::AWAITING_PAYMENT,
            'payment_due_at' => now()->addHours(72),
        ], $settlement));

        return [$row, $winner, $auction->seller];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function attempt(AuctionSettlement $settlement, array $attributes = []): SettlementPayment
    {
        return SettlementPayment::create(array_merge([
            'settlement_id' => $settlement->id,
            'provider' => 'paystack',
            'reference' => 'AUC-'.Str::upper(Str::random(20)),
            'amount_cents' => $settlement->amount_cents,
            'currency' => $settlement->currency,
            'status' => SettlementPayment::INITIATED,
            'authorization_url' => 'https://checkout.paystack.com/'.Str::lower(Str::random(12)),
            'initiated_by' => $settlement->winner_id,
            'expires_at' => now()->addMinutes(30),
        ], $attributes));
    }

    /**
     * A charge.success webhook body for this attempt, with optional overrides to `data`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function chargeSuccess(SettlementPayment $payment, array $data = [], string $event = 'charge.success'): array
    {
        return ['event' => $event, 'data' => array_merge([
            'id' => 4099260516,
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => $payment->amount_cents,
            'currency' => $payment->currency,
            'paid_at' => '2026-10-18T12:00:00.000Z',
            'channel' => 'card',
            'authorization' => ['authorization_code' => 'AUTH_not_for_storage', 'last4' => '4081'],
        ], $data)];
    }

    protected function sign(string $raw, ?string $key = null): string
    {
        return hash_hmac('sha512', $raw, $key ?? $this->testKey);
    }

    /**
     * POST the exact raw bytes with a valid signature (unless told otherwise).
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $server  server-style headers, e.g. HTTP_X_PAYSTACK_SIGNATURE
     */
    protected function webhook(array|string $payload, ?string $signature = null, array $server = []): TestResponse
    {
        $raw = is_string($payload) ? $payload : json_encode($payload);

        return $this->call('POST', '/webhooks/paystack', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? $this->sign($raw),
        ], $server), $raw);
    }
}
