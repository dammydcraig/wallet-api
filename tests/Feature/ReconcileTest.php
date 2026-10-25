<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MakesWallets;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use MakesWallets, RefreshDatabase;

    private function activity(): array
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(500_000), $this->userWithBalance(100_000)];
        $this->postJson('/api/transfers', ['to_wallet_id' => $b->wallet->id, 'amount' => 70_000], $this->headers($a))->assertCreated();

        return [$a, $b];
    }

    public function test_a_healthy_ledger_passes(): void
    {
        $this->activity();
        $this->artisan('wallet:reconcile')->expectsOutputToContain('consistent')->assertExitCode(0);
    }

    public function test_an_empty_ledger_passes(): void
    {
        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }

    public function test_money_created_out_of_thin_air_is_caught(): void
    {
        [$a] = $this->activity();
        LedgerEntry::where('wallet_id', $a->wallet->id)->first()->increment('amount', 1);

        $this->artisan('wallet:reconcile')->assertExitCode(1);
    }

    public function test_an_overdrawn_user_wallet_is_caught(): void
    {
        [$a, $b] = $this->activity();
        $t = Transfer::create(['from_wallet_id' => $a->wallet->id, 'to_wallet_id' => $b->wallet->id, 'amount' => 9_999_999, 'currency' => 'NGN']);
        LedgerEntry::create(['wallet_id' => $a->wallet->id, 'transfer_id' => $t->id, 'amount' => -9_999_999]);
        LedgerEntry::create(['wallet_id' => $b->wallet->id, 'transfer_id' => $t->id, 'amount' => 9_999_999]);

        $this->artisan('wallet:reconcile')->expectsOutputToContain('overdrawn')->assertExitCode(1);
    }

    public function test_a_transfer_missing_its_entries_is_caught(): void
    {
        [$a] = $this->activity();
        LedgerEntry::where('wallet_id', $a->wallet->id)->orderByDesc('id')->first()->delete();

        $this->artisan('wallet:reconcile')->assertExitCode(1);
    }

    public function test_a_transfer_amount_that_disagrees_with_its_entries_is_caught(): void
    {
        $this->activity();
        Transfer::whereNotNull('user_id')->update(['amount' => 1]);

        $this->artisan('wallet:reconcile')->expectsOutputToContain('does not match')->assertExitCode(1);
    }

    public function test_extra_entries_on_a_transfer_are_caught_even_when_they_net_to_zero(): void
    {
        [$a, $b] = $this->activity();
        $t = Transfer::whereNotNull('user_id')->first();
        LedgerEntry::create(['wallet_id' => $a->wallet->id, 'transfer_id' => $t->id, 'amount' => 5]);
        LedgerEntry::create(['wallet_id' => $b->wallet->id, 'transfer_id' => $t->id, 'amount' => -5]);

        $this->artisan('wallet:reconcile')->expectsOutputToContain('does not match')->assertExitCode(1);
    }

    public function test_it_is_scheduled_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'wallet:reconcile'));

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_funding_refuses_in_production_and_rejects_bad_amounts(): void
    {
        $u = $this->userWithBalance();

        $this->artisan('wallet:fund', ['email' => $u->email, 'amount' => '-5'])->assertExitCode(1);
        $this->artisan('wallet:fund', ['email' => $u->email, 'amount' => '12.5'])->assertExitCode(1);
        $this->artisan('wallet:fund', ['email' => $u->email, 'amount' => '5000'])->assertExitCode(0);
        $this->assertSame(5_000, $u->wallet->balance());

        $this->app['env'] = 'production';
        $this->artisan('wallet:fund', ['email' => $u->email, 'amount' => '5000'])->assertExitCode(1);
        $this->assertSame(5_000, $u->wallet->balance());
    }
}
