<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create($data);
            Wallet::create(['user_id' => $user->id, 'kind' => Wallet::USER, 'currency' => 'NGN']);

            return $user;
        });

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'wallet_id' => $user->wallet->id,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $limiterKey = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json(['error' => 'too_many_attempts', 'retry_after' => RateLimiter::availableIn($limiterKey)], 429);
        }

        $user = User::where('email', $data['email'])->first();
        // Hash::check runs even for an unknown email, so response time does not reveal which emails exist.
        $ok = Hash::check($data['password'], $user?->password ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

        if (! $user || ! $ok) {
            RateLimiter::hit($limiterKey, 60);

            return response()->json(['error' => 'invalid_credentials', 'message' => 'Email or password is incorrect.'], 401);
        }

        RateLimiter::clear($limiterKey);

        return response()->json(['token' => $user->createToken('api')->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete(); // revokes this token only

        return response()->json(['message' => 'Logged out.']);
    }
}
