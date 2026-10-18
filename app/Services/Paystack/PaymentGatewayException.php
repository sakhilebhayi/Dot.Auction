<?php

namespace App\Services\Paystack;

use RuntimeException;

/**
 * Anything that stopped us talking to Paystack. The message is always safe to show a user: it never
 * carries a key, a URL, a response body or a library error. `reason` is a short machine code for
 * logs and tests; `isTemporary()` says whether trying again later may help.
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, private readonly bool $temporary = false)
    {
        parent::__construct($message);
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }
}
