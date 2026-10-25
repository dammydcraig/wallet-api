<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileWallets extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Check the ledger invariants and fail loudly if any is broken';

    public function handle(): int
    {
        $problems = [];

        // 1. Every transfer must net to zero: what left one wallet arrived in another.
        //    (If every transfer nets to zero, the whole ledger does too: money is moved, never created.)
        $unbalanced = LedgerEntry::select('transfer_id', DB::raw('SUM(amount) as net'))
            ->groupBy('transfer_id')->havingRaw('SUM(amount) <> 0')->get();
        foreach ($unbalanced as $row) {
            $problems[] = "transfer {$row->transfer_id} nets to {$row->net}, not 0";
        }

        // 2. Every transfer must have exactly two entries matching its amount and wallets.
        foreach (Transfer::with('entries')->cursor() as $t) {
            $debit = $t->entries->firstWhere('amount', -$t->amount);
            $credit = $t->entries->firstWhere('amount', $t->amount);
            if ($t->entries->count() !== 2 || ! $debit || ! $credit
                || $debit->wallet_id !== $t->from_wallet_id || $credit->wallet_id !== $t->to_wallet_id) {
                $problems[] = "transfer {$t->id} does not match its ledger entries";
            }
        }

        // 3. No user wallet may be overdrawn. Only the system wallet may be negative.
        $overdrawn = Wallet::where('kind', Wallet::USER)
            ->whereRaw('(SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE wallet_id = wallets.id) < 0')->get();
        foreach ($overdrawn as $w) {
            $problems[] = "wallet {$w->id} is overdrawn";
        }

        if ($problems === []) {
            $this->info('Ledger is consistent.');

            return self::SUCCESS;
        }

        foreach ($problems as $p) {
            $this->error($p);
        }
        Log::critical('wallet:reconcile found problems', ['problems' => $problems]);

        return self::FAILURE;
    }
}
