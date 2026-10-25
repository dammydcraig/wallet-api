<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferRequest;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TransferController extends Controller
{
    public function store(TransferRequest $request, TransferService $transfers): JsonResponse
    {
        [$transfer, $replayed] = $transfers->send(
            $request->user(),
            $request->header('Idempotency-Key'),
            $request->validated('to_wallet_id'),
            (int) $request->validated('amount'),
            $request->validated('note'),
        );

        return response()->json($this->present($transfer), $replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(Transfer $transfer): JsonResponse
    {
        // 404, not 403: do not confirm that someone else's transfer exists.
        abort_if(Gate::forUser(request()->user())->denies('view', $transfer), 404);

        return response()->json($this->present($transfer));
    }

    private function present(Transfer $t): array
    {
        return [
            'id' => $t->id,
            'from_wallet_id' => $t->from_wallet_id,
            'to_wallet_id' => $t->to_wallet_id,
            'amount' => $t->amount,
            'currency' => $t->currency,
            'note' => $t->note,
            'created_at' => $t->created_at,
        ];
    }
}
