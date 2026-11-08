<?php

namespace App\Services\Auctions;

use RuntimeException;

/**
 * The person still has live obligations (see AccountEraser). `reasons` are plain sentences safe to show to them.
 */
class AccountDeletionBlocked extends RuntimeException
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Your account cannot be deleted yet. '.implode(' ', $reasons));
    }
}
