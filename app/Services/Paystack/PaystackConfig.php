<?php

namespace App\Services\Paystack;

use Illuminate\Support\Facades\Log;

/**
 * The one place that decides whether Paystack may be used, and with which key.
 *
 * TEST MODE ONLY by default. `sk_test_` keys work. An `sk_live_` key is refused (never sent, never
 * handed to the client, never printed) unless PAYSTACK_ALLOW_LIVE=true is ALSO set. Any other prefix,
 * or a base URL that is not https, is refused. With no key at all payments are simply off:
 * isConfigured() is false and nothing is flagged as wrong.
 */
class PaystackConfig
{
    public function isConfigured(): bool
    {
        return $this->rawKey() !== '';
    }

    /** A key is set but must not be used (live without the second switch, unknown prefix, bad URL). */
    public function isMisconfigured(): bool
    {
        return $this->isConfigured() && $this->problemCode() !== null;
    }

    /** Payments are switched on and the setup is acceptable. */
    public function canTakePayments(): bool
    {
        return $this->isConfigured() && $this->problemCode() === null;
    }

    /** 'test', 'live', or null when payments cannot be taken. */
    public function mode(): ?string
    {
        if (! $this->canTakePayments()) {
            return null;
        }

        return str_starts_with($this->rawKey(), 'sk_live_') ? 'live' : 'test';
    }

    public function isTestMode(): bool
    {
        return $this->mode() === 'test';
    }

    /** Safe sentence for an operator when a key is set but refused; null otherwise. */
    public function problem(): ?string
    {
        return match ($this->problemCode()) {
            'live_key_refused' => 'A live Paystack key is set but live payments are not allowed. Nothing will be sent. Use a test key, or set PAYSTACK_ALLOW_LIVE=true on purpose.',
            'bad_key' => 'The Paystack secret key is not a secret key (sk_test_ or sk_live_). Nothing will be sent.',
            'bad_url' => 'The Paystack base URL is not https. Nothing will be sent.',
            default => null,
        };
    }

    /**
     * The secret key, only when it may be used.
     *
     * @throws PaymentGatewayException when payments are off or the setup is refused
     */
    public function secretKey(): string
    {
        if (! $this->isConfigured()) {
            throw new PaymentGatewayException('Online payment is not set up.', 'not_configured');
        }

        $code = $this->problemCode();

        if ($code !== null) {
            // Logged without the key, so the refusal is visible to whoever runs the server.
            Log::warning('Paystack refused: '.$code.'. Nothing was sent.');

            throw new PaymentGatewayException(
                $code === 'live_key_refused'
                    ? 'Online payment is switched off because a live key is set without live payments being allowed.'
                    : 'Online payment is not set up correctly.',
                $code === 'bad_url' ? 'not_configured' : $code,
            );
        }

        return $this->rawKey();
    }

    /** The key for checking a webhook signature; null whenever the key may not be used. */
    public function keyForVerification(): ?string
    {
        return $this->canTakePayments() ? $this->rawKey() : null;
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('paystack.base_url'), '/');
    }

    public function timeout(): int
    {
        return max(1, (int) config('paystack.timeout', 10));
    }

    public function attemptTtlMinutes(): int
    {
        $minutes = (int) config('paystack.attempt_ttl_minutes', 30);

        return $minutes > 0 ? $minutes : 30;
    }

    public function paymentWindowHours(): int
    {
        $hours = (int) config('auction.payment_window_hours', 72);

        return $hours > 0 ? $hours : 72;
    }

    private function rawKey(): string
    {
        return trim((string) config('paystack.secret_key'));
    }

    private function problemCode(): ?string
    {
        $key = $this->rawKey();

        if (! str_starts_with($this->baseUrl(), 'https://')) {
            return 'bad_url';
        }

        if (str_starts_with($key, 'sk_live_')) {
            return config('paystack.allow_live') === true ? null : 'live_key_refused';
        }

        return str_starts_with($key, 'sk_test_') ? null : 'bad_key';
    }
}
