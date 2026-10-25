<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidRecipient extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'invalid_recipient', 'message' => 'That wallet cannot receive this transfer.'], 422);
    }
}
