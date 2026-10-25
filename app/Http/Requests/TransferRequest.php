<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_wallet_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], // minor units, whole numbers only
            'note' => ['nullable', 'string', 'max:140'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $key = $this->header('Idempotency-Key');
            if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $key)) {
                $validator->errors()->add('idempotency_key', 'An Idempotency-Key header of 8 to 64 letters, digits, "-" or "_" is required.');
            }
        }];
    }
}
