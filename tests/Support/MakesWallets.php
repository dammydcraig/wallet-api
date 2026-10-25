<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\Wallet;
use App\Services\TransferService;

trait MakesWallets
{
    /** A user with a wallet holding $kobo, and a Sanctum token for them. */
    protected function userWithBalance(int $kobo = 0): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'kind' => Wallet::USER, 'currency' => 'NGN']);
        $user->setRelation('wallet', $user->wallet()->first());
        if ($kobo > 0) {
            app(TransferService::class)->fund($user->wallet, $kobo);
        }

        return $user;
    }

    protected function headers(User $user, ?string $key = 'key-0001-abcd'): array
    {
        // The test client reuses the resolved guard across requests in one test; reset it so each request authenticates afresh.
        app('auth')->forgetGuards();
        $h = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken, 'Accept' => 'application/json'];
        if ($key) {
            $h['Idempotency-Key'] = $key;
        }

        return $h;
    }
}
