<?php

namespace App\Services;

use App\Exceptions\IdempotencyKeyReused;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\InvalidRecipient;
use App\Mail\TransferReceipt;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TransferService
{
    /**
     * Move money between two wallets exactly once per idempotency key.
     *
     * @return array{0: Transfer, 1: bool} the transfer, and whether it was a replay of an earlier request
     */
    public function send(User $user, string $key, string $toWalletId, int $amount, ?string $note): array
    {
        $hash = hash('sha256', json_encode([$toWalletId, $amount, $note]));

        try {
            return DB::transaction(fn () => $this->perform($user, $key, $hash, $toWalletId, $amount, $note));
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced past the application check; the unique index stopped the second.
            $existing = Transfer::where('user_id', $user->id)->where('idempotency_key', $key)->firstOrFail();

            return $this->replay($existing, $hash);
        }
    }

    /** Credit a user from the system wallet (demo funding). The system wallet is the only one allowed below zero. */
    public function fund(Wallet $to, int $amount, ?string $note = 'Funding'): Transfer
    {
        return DB::transaction(function () use ($to, $amount, $note) {
            $system = Wallet::system();

            return $this->post($system, $to, $amount, null, null, null, $note);
        });
    }

    private function perform(User $user, string $key, string $hash, string $toWalletId, int $amount, ?string $note): array
    {
        $from = $user->wallet;

        // Lock both wallets in a fixed order (by id) so two opposite transfers cannot deadlock.
        $ids = [$from->id, $toWalletId];
        sort($ids);
        $locked = Wallet::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Checked after the lock: the sender's own requests are serialized here, so a concurrent
        // duplicate waits and then sees the first one's result instead of running twice.
        $existing = Transfer::where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        $to = $locked->get($toWalletId);
        if (! $to || $to->kind !== Wallet::USER || $to->id === $from->id || $to->currency !== $from->currency) {
            throw new InvalidRecipient;
        }

        if ($from->balance() < $amount) {
            throw new InsufficientFunds;
        }

        $transfer = $this->post($from, $to, $amount, $user->id, $key, $hash, $note);

        // Queued after the commit, so a rolled-back transfer never sends a receipt.
        Mail::to($user->email)->queue((new TransferReceipt($transfer))->afterCommit());

        return [$transfer, false];
    }

    private function post(Wallet $from, Wallet $to, int $amount, ?int $userId, ?string $key, ?string $hash, ?string $note): Transfer
    {
        $transfer = Transfer::create([
            'user_id' => $userId,
            'from_wallet_id' => $from->id,
            'to_wallet_id' => $to->id,
            'amount' => $amount,
            'currency' => $from->currency,
            'note' => $note,
            'idempotency_key' => $key,
            'request_hash' => $hash,
        ]);

        LedgerEntry::create(['wallet_id' => $from->id, 'transfer_id' => $transfer->id, 'amount' => -$amount]);
        LedgerEntry::create(['wallet_id' => $to->id, 'transfer_id' => $transfer->id, 'amount' => $amount]);

        return $transfer;
    }

    private function replay(Transfer $existing, string $hash): array
    {
        if (! hash_equals((string) $existing->request_hash, $hash)) {
            throw new IdempotencyKeyReused;
        }

        return [$existing, true];
    }
}
