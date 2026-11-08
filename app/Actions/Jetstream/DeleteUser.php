<?php

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Services\Auctions\AccountDeletionBlocked;
use App\Services\Auctions\AccountEraser;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected AccountEraser $eraser) {}

    /**
     * "Delete" the given user. Auction data belongs to other people too (bids, sales, settlements), so the account is
     * anonymised rather than removed, and refused while the person has live obligations. See AccountEraser.
     *
     * @throws ValidationException when the person still has live obligations (shown under the password field)
     */
    public function delete(User $user): void
    {
        try {
            $this->eraser->erase($user);
        } catch (AccountDeletionBlocked $e) {
            throw ValidationException::withMessages(['password' => [$e->getMessage()]]);
        }
    }
}
