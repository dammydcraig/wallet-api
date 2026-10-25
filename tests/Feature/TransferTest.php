<?php

namespace Tests\Feature;

use App\Mail\TransferReceipt;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MakesWallets;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use MakesWallets, RefreshDatabase;

    private function send($user, $to, int $amount, ?string $key = 'key-0001-abcd', array $extra = [])
    {
        return $this->postJson('/api/transfers', array_merge(['to_wallet_id' => $to->wallet->id, 'amount' => $amount], $extra), $this->headers($user, $key));
    }

    public function test_a_transfer_moves_money_and_balances_are_derived_from_entries(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(500_000), $this->userWithBalance()];

        $this->send($a, $b, 120_000)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');

        $this->assertSame(380_000, $a->wallet->balance());
        $this->assertSame(120_000, $b->wallet->balance());
        $this->getJson('/api/wallet', $this->headers($b, null))->assertOk()->assertJsonPath('balance', 120_000);
    }

    public function test_every_transfer_writes_two_entries_that_net_to_zero(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(100_000), $this->userWithBalance()];
        $id = $this->send($a, $b, 25_000)->json('id');

        $entries = LedgerEntry::where('transfer_id', $id)->get();
        $this->assertCount(2, $entries);
        $this->assertSame(0, (int) $entries->sum('amount'));
    }

    public function test_insufficient_funds_is_refused_and_changes_nothing(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(10_000), $this->userWithBalance()];

        $this->send($a, $b, 10_001)->assertUnprocessable()->assertJsonPath('error', 'insufficient_funds');

        $this->assertSame(10_000, $a->wallet->balance());
        $this->assertSame(0, $b->wallet->balance());
        $this->assertSame(0, Transfer::whereNotNull('user_id')->count());
        Mail::assertNothingQueued();
    }

    public function test_the_whole_balance_can_be_sent_but_not_one_kobo_more(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(10_000), $this->userWithBalance()];

        $this->send($a, $b, 10_000)->assertCreated();
        $this->assertSame(0, $a->wallet->balance());
        $this->send($a, $b, 1, 'key-0002-abcd')->assertUnprocessable();
    }

    public function test_retrying_with_the_same_key_does_not_move_money_twice(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(500_000), $this->userWithBalance()];

        $first = $this->send($a, $b, 100_000)->assertCreated();
        $second = $this->send($a, $b, 100_000)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(400_000, $a->wallet->balance());
        $this->assertSame(100_000, $b->wallet->balance());
        $this->assertSame(1, Transfer::whereNotNull('user_id')->count());
    }

    public function test_a_replay_still_succeeds_when_the_balance_would_no_longer_cover_it(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(100_000), $this->userWithBalance()];

        $this->send($a, $b, 100_000)->assertCreated();
        // Balance is now zero. The retry must report the original result, not "insufficient funds".
        $this->send($a, $b, 100_000)->assertOk();
    }

    public function test_the_same_key_with_a_different_request_is_a_conflict(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(500_000), $this->userWithBalance()];

        $this->send($a, $b, 100_000)->assertCreated();
        $this->send($a, $b, 999)->assertStatus(409)->assertJsonPath('error', 'idempotency_key_reused');

        $this->assertSame(400_000, $a->wallet->balance());
    }

    public function test_a_different_user_can_reuse_the_same_key_string(): void
    {
        Mail::fake();
        [$a, $b, $c] = [$this->userWithBalance(100_000), $this->userWithBalance(100_000), $this->userWithBalance()];

        $this->send($a, $c, 1_000)->assertCreated();
        $this->send($b, $c, 1_000)->assertCreated();
        $this->assertSame(2_000, $c->wallet->balance());
    }

    public function test_the_unique_index_stops_a_duplicate_even_if_the_app_check_is_bypassed(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(100_000), $this->userWithBalance()];
        $this->send($a, $b, 1_000)->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);
        Transfer::create([
            'user_id' => $a->id, 'from_wallet_id' => $a->wallet->id, 'to_wallet_id' => $b->wallet->id,
            'amount' => 1_000, 'currency' => 'NGN', 'idempotency_key' => 'key-0001-abcd', 'request_hash' => 'x',
        ]);
    }

    public function test_the_idempotency_key_header_is_required_and_checked(): void
    {
        [$a, $b] = [$this->userWithBalance(100_000), $this->userWithBalance()];

        $this->send($a, $b, 1_000, null)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->send($a, $b, 1_000, 'short')->assertUnprocessable();
        $this->send($a, $b, 1_000, 'has spaces and !!! chars')->assertUnprocessable();
    }

    public function test_amounts_must_be_positive_whole_numbers(): void
    {
        [$a, $b] = [$this->userWithBalance(100_000), $this->userWithBalance()];

        foreach ([0, -500, 10.5, '10.50', 'abc', null] as $bad) {
            $this->send($a, $b, 0, 'key-0001-abcd', ['amount' => $bad])->assertUnprocessable();
        }
        $this->assertSame(100_000, $a->wallet->balance());
    }

    public function test_you_cannot_send_to_yourself_the_system_wallet_or_a_missing_wallet(): void
    {
        Mail::fake();
        $a = $this->userWithBalance(100_000);
        $system = Wallet::system();

        $this->send($a, $a, 1_000)->assertUnprocessable()->assertJsonPath('error', 'invalid_recipient');
        $this->postJson('/api/transfers', ['to_wallet_id' => $system->id, 'amount' => 1_000], $this->headers($a, 'key-0002-abcd'))
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_recipient');
        $this->postJson('/api/transfers', ['to_wallet_id' => (string) Str::uuid(), 'amount' => 1_000], $this->headers($a, 'key-0003-abcd'))
            ->assertUnprocessable();
    }

    public function test_the_sender_is_always_the_authenticated_user_whatever_the_body_says(): void
    {
        Mail::fake();
        [$a, $victim, $c] = [$this->userWithBalance(1_000), $this->userWithBalance(900_000), $this->userWithBalance()];

        $this->send($a, $c, 500, 'key-0001-abcd', ['from_wallet_id' => $victim->wallet->id, 'user_id' => $victim->id])->assertCreated();

        $this->assertSame(900_000, $victim->wallet->balance());
        $this->assertSame(500, $a->wallet->balance());
    }

    public function test_a_receipt_is_queued_once_and_not_again_on_a_replay(): void
    {
        Mail::fake();
        [$a, $b] = [$this->userWithBalance(100_000), $this->userWithBalance()];

        $this->send($a, $b, 1_000)->assertCreated();
        $this->send($a, $b, 1_000)->assertOk();

        Mail::assertQueued(TransferReceipt::class, 1);
        Mail::assertQueued(TransferReceipt::class, fn ($m) => $m->hasTo($a->email));
    }

    public function test_the_receipt_renders_the_amount_in_naira(): void
    {
        [$a, $b] = [$this->userWithBalance(200_000), $this->userWithBalance()];
        $t = Transfer::find($this->send($a, $b, 123_456)->json('id'));

        $html = (new TransferReceipt($t))->render();
        $this->assertStringContainsString('NGN 1,234.56', $html);
        $this->assertStringContainsString($t->id, $html);
    }

    public function test_only_the_two_parties_can_read_a_transfer(): void
    {
        Mail::fake();
        [$a, $b, $outsider] = [$this->userWithBalance(100_000), $this->userWithBalance(), $this->userWithBalance()];
        $id = $this->send($a, $b, 1_000)->json('id');

        $this->getJson("/api/transfers/$id", $this->headers($a, null))->assertOk();
        $this->getJson("/api/transfers/$id", $this->headers($b, null))->assertOk();
        $this->getJson("/api/transfers/$id", $this->headers($outsider, null))->assertNotFound();
    }

    public function test_transfers_need_authentication(): void
    {
        $this->postJson('/api/transfers', ['to_wallet_id' => 'x', 'amount' => 1], ['Idempotency-Key' => 'key-0001-abcd'])->assertUnauthorized();
    }

    public function test_the_wallet_endpoint_only_shows_your_own_entries(): void
    {
        Mail::fake();
        [$a, $b, $outsider] = [$this->userWithBalance(100_000), $this->userWithBalance(), $this->userWithBalance()];
        $this->send($a, $b, 1_000);

        $this->getJson('/api/wallet', $this->headers($outsider, null))->assertOk()
            ->assertJsonPath('balance', 0)->assertJsonCount(0, 'entries');
    }
}
