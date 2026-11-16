<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_slug' => ['nullable', 'string', 'exists:plans,slug'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'payment_type' => ['required', 'string', 'in:bank_transfer,qris'],
            'bank' => ['required_if:payment_type,bank_transfer', 'nullable', 'string', 'in:bca,bni,bri,permata,mandiri'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'code' => 422,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422));
    }
}
