<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class IdempotencyKeyReused extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'idempotency_key_reused',
            'message' => 'This Idempotency-Key was already used with a different request. Use a new key for a new transfer.',
        ], 409);
    }
}
