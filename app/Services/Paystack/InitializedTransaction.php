<?php

namespace App\Services\Paystack;

final readonly class InitializedTransaction
{
    public function __construct(
        public string $authorizationUrl,
        public string $accessCode,
        public string $reference,
    ) {}
}
