<?php

namespace App\Policies;

use App\Models\Transfer;
use App\Models\User;

class TransferPolicy
{
    /** Only the two parties to a transfer may see it. */
    public function view(User $user, Transfer $transfer): bool
    {
        $wallet = $user->wallet;

        return $wallet && in_array($wallet->id, [$transfer->from_wallet_id, $transfer->to_wallet_id], true);
    }
}
