<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /** There is no wallet id in the URL: you can only ever read your own, so there is nothing to tamper with. */
    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        return response()->json([
            'id' => $wallet->id,
            'currency' => $wallet->currency,
            'balance' => $wallet->balance(),
            'entries' => $wallet->entries()->latest('id')->limit(50)
                ->get(['id', 'transfer_id', 'amount', 'created_at']),
        ]);
    }
}
