<?php

namespace App\Services\Paystack;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * What Paystack says about a transaction, from either GET /transaction/verify or a charge.success
 * webhook. `amountCents` is Paystack's subunit amount (cents for ZAR) as an integer, or null when
 * the value was not a plain whole number, which never matches anything.
 */
final readonly class VerifiedTransaction
{
    public function __construct(
        public string $status,
        public ?int $amountCents,
        public string $currency,
        public string $reference,
        public ?string $transactionId,
        public ?string $paidAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Paystack's `data` object
     */
    public static function fromPayload(array $data): self
    {
        $amount = $data['amount'] ?? null;
        $cents = is_int($amount) ? $amount : (is_float($amount) && floor($amount) === $amount ? (int) $amount : null);

        return new self(
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            amountCents: $cents !== null && $cents >= 0 ? $cents : null,
            currency: is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : '',
            reference: is_string($data['reference'] ?? null) ? $data['reference'] : '',
            transactionId: is_int($data['id'] ?? null) || (is_string($data['id'] ?? null) && ctype_digit($data['id'])) ? (string) $data['id'] : null,
            paidAt: is_string($data['paid_at'] ?? null) ? $data['paid_at'] : null,
        );
    }

    public function withReference(string $reference): self
    {
        return new self($this->status, $this->amountCents, $this->currency, $reference, $this->transactionId, $this->paidAt);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    public function paidAtOrNow(): Carbon
    {
        try {
            return $this->paidAt !== null ? Carbon::parse($this->paidAt) : now();
        } catch (Throwable) {
            return now();
        }
    }
}
