<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InsufficientFunds extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'insufficient_funds', 'message' => 'Your balance is too low for this transfer.'], 422);
    }
}
