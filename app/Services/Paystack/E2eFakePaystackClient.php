<?php

namespace App\Services\Paystack;

use Illuminate\Support\Facades\Cache;

/**
 * TESTING ONLY. A stand-in for PaystackClient that the real-browser suite (tests/e2e/auction) binds so a payment can be
 * walked through without a network: Laravel's Http::fake() lives inside one PHP process and cannot reach a browser run.
 *
 * It is bound by AppServiceProvider only when ALL of these hold: the app runs in the "local" or "testing" environment,
 * and config('paystack.e2e_fake') is true (env AUCTION_E2E_FAKE_PAYSTACK=1). No production code path reads this class.
 *
 * What it does (state lives in the cache so the server process and the test runner agree):
 *  - initializeTransaction() answers a checkout address on this very app (/dev/fake-paystack/{reference}) unless the
 *    runner switched the mode to "unreachable" or "error" (then it throws the same exceptions PaystackClient throws);
 *  - verifyTransaction() reports what the person pressed on the fake checkout page (success, failed) or "abandoned".
 */
class E2eFakePaystackClient extends PaystackClient
{
    public const MODE_KEY = 'e2e-paystack:mode';

    public static function ref(string $reference, string $what): string
    {
        return 'e2e-paystack:'.$what.':'.$reference;
    }

    public function initializeTransaction(string $email, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata): InitializedTransaction
    {
        $this->failIfSwitchedOff();

        Cache::put(self::ref($reference, 'init'), ['amount' => $amountCents, 'currency' => $currency, 'callback' => $callbackUrl, 'metadata' => $metadata], now()->addHour());

        return new InitializedTransaction(url('/dev/fake-paystack/'.$reference), 'e2e_'.$reference, $reference);
    }

    public function verifyTransaction(string $reference): VerifiedTransaction
    {
        $this->failIfSwitchedOff();

        $init = Cache::get(self::ref($reference, 'init'));
        $result = Cache::get(self::ref($reference, 'result'), 'abandoned');

        return new VerifiedTransaction(
            status: $result === 'success' ? 'success' : ($result === 'pending' ? 'ongoing' : (string) $result),
            amountCents: $init['amount'] ?? 0,
            currency: $init['currency'] ?? 'ZAR',
            reference: $reference,
            transactionId: (string) (100000000 + abs(crc32($reference))),
            paidAt: $result === 'success' ? now()->toIso8601String() : null,
        );
    }

    private function failIfSwitchedOff(): void
    {
        $mode = Cache::get(self::MODE_KEY, 'ok');

        if ($mode === 'unreachable') {
            throw new PaymentGatewayException('Paystack could not be reached. Nothing was charged. Please try again in a moment.', 'unreachable', true);
        }

        if ($mode === 'error') {
            throw new PaymentGatewayException('Paystack is having trouble right now. Nothing was charged. Please try again in a moment.', 'http_500', true);
        }
    }
}
