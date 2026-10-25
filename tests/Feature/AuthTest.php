<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $over = [])
    {
        return $this->postJson('/api/register', array_merge([
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a-long-password-1',
        ], $over));
    }

    public function test_register_returns_a_token_and_creates_a_wallet(): void
    {
        $res = $this->register()->assertCreated()->assertJsonStructure(['token', 'wallet_id']);

        $this->assertSame(0, User::first()->wallet->balance());
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_register_rejects_weak_passwords_and_duplicate_emails(): void
    {
        $this->register(['password' => 'short'])->assertUnprocessable();
        $this->register()->assertCreated();
        $this->register()->assertUnprocessable();
    }

    public function test_password_is_hashed_and_never_returned(): void
    {
        $res = $this->register()->assertCreated();

        $this->assertStringNotContainsString('password', json_encode($res->json()));
        $this->assertNotSame('a-long-password-1', User::first()->password);
    }

    public function test_login_works_with_the_right_password_only(): void
    {
        $this->register();

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'a-long-password-1'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password-1'])
            ->assertUnauthorized();
    }

    public function test_unknown_email_and_wrong_password_give_the_same_answer(): void
    {
        $this->register();

        $a = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever-1234'])->assertUnauthorized();
        $b = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'whatever-1234'])->assertUnauthorized();

        $this->assertSame($a->json(), $b->json());
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $this->register();
        RateLimiter::clear('login:ada@example.com|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password-1'])->assertUnauthorized();
        }

        // Even the right password is refused while locked out.
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'a-long-password-1'])->assertStatus(429);
    }

    public function test_protected_routes_need_a_valid_token(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();
        $this->getJson('/api/wallet', ['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->register()->json('token');
        $h = ['Authorization' => "Bearer $token"];

        $this->getJson('/api/wallet', $h)->assertOk();
        $this->postJson('/api/logout', [], $h)->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tokens_expire(): void
    {
        $token = $this->register()->json('token');
        $this->travel(25)->hours();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/wallet', ['Authorization' => "Bearer $token"])->assertUnauthorized();
    }
}
