<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('Idempotency-Key');
        $this->merge([
            'idempotency_key' => is_string($headerKey) ? trim($headerKey) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:1', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
