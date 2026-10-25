<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TransferService;
use Illuminate\Console\Command;

class FundWallet extends Command
{
    protected $signature = 'wallet:fund {email} {amount : Amount in minor units (kobo)}';

    protected $description = 'Credit a wallet from the system wallet (demo only; refuses to run in production)';

    public function handle(TransferService $transfers): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to fund wallets in production.');

            return self::FAILURE;
        }

        $amount = filter_var($this->argument('amount'), FILTER_VALIDATE_INT);
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || ! $user->wallet || $amount === false || $amount <= 0) {
            $this->error('Unknown user, or amount is not a positive whole number.');

            return self::FAILURE;
        }

        $transfers->fund($user->wallet, $amount);
        $this->info("Credited {$amount} kobo. Balance is now {$user->wallet->balance()}.");

        return self::SUCCESS;
    }
}
