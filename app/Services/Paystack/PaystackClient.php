<?php

namespace App\Services\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only code that talks to Paystack: initialize a transaction, verify one.
 *
 * Built to be harmless when things go wrong. The base URL is pinned to https and read from config
 * only; the call has a 10 second timeout, follows no redirects and is not retried; the key is
 * fetched through PaystackConfig (which refuses a live key unless explicitly allowed, before any
 * request is made). Nothing here logs the key, a request or response body, an email address or a
 * checkout link: failures log the endpoint name and HTTP status only. Every failure becomes a
 * PaymentGatewayException with a message that is safe to show to a user.
 */
class PaystackClient
{
    public function __construct(private readonly PaystackConfig $config) {}

    /**
     * @param  int  $amountCents  Paystack's "subunit": cents for ZAR
     * @param  array<string, scalar|null>  $metadata
     *
     * @throws PaymentGatewayException
     */
    public function initializeTransaction(string $email, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata): InitializedTransaction
    {
        $data = $this->send('initialize', fn (PendingRequest $http) => $http->post($this->config->baseUrl().'/transaction/initialize', [
            'email' => $email,
            'amount' => $amountCents,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            // Paystack takes the metadata as a JSON-encoded string.
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));

        $url = $data['authorization_url'] ?? null;
        $code = $data['access_code'] ?? null;
        $echoed = $data['reference'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($code) || ! is_string($echoed)) {
            throw $this->unexpected('initialize');
        }

        return new InitializedTransaction($url, $code, $echoed);
    }

    /**
     * @throws PaymentGatewayException
     */
    public function verifyTransaction(string $reference): VerifiedTransaction
    {
        $data = $this->send('verify', fn (PendingRequest $http) => $http->get($this->config->baseUrl().'/transaction/verify/'.rawurlencode($reference)));

        $transaction = VerifiedTransaction::fromPayload($data);

        if ($transaction->status === '' || $transaction->amountCents === null || $transaction->reference === '' || $transaction->currency === '') {
            throw $this->unexpected('verify');
        }

        return $transaction;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed> Paystack's `data` object
     */
    private function send(string $operation, callable $call): array
    {
        // Throws before any request when payments are off or the key is refused.
        $key = $this->config->secretKey();

        try {
            $response = $call(
                Http::withToken($key)
                    ->acceptJson()
                    ->timeout($this->config->timeout())
                    ->connectTimeout($this->config->timeout())
                    ->withoutRedirecting()
            );
        } catch (ConnectionException) {
            Log::warning('Paystack unreachable.', ['operation' => $operation]);

            throw new PaymentGatewayException('Paystack could not be reached. Nothing was charged. Please try again in a moment.', 'unreachable', true);
        } catch (Throwable $e) {
            Log::warning('Paystack call failed unexpectedly.', ['operation' => $operation, 'error' => class_basename($e)]);

            throw new PaymentGatewayException('Paystack could not be reached. Nothing was charged. Please try again in a moment.', 'unreachable', true);
        }

        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            Log::warning('Paystack answered with an error.', ['operation' => $operation, 'status' => $status]);

            $temporary = $status >= 500 || $status === 429;

            throw new PaymentGatewayException(
                $temporary
                    ? 'Paystack is having trouble right now. Nothing was charged. Please try again in a moment.'
                    : 'Paystack did not accept the request. Nothing was charged. If this keeps happening, tell the platform operator.',
                'http_'.$status,
                $temporary,
            );
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== true || ! is_array($json['data'] ?? null)) {
            throw $this->unexpected($operation);
        }

        return $json['data'];
    }

    private function unexpected(string $operation): PaymentGatewayException
    {
        Log::warning('Paystack sent a response in an unexpected shape.', ['operation' => $operation]);

        return new PaymentGatewayException('Paystack sent an answer this app could not understand. Nothing was charged. Please try again later.', 'unexpected_response', true);
    }
}
