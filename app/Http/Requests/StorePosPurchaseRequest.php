<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePosPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('Idempotency-Key');
        $merged = [
            'idempotency_key' => is_string($headerKey) ? trim($headerKey) : null,
        ];

        $storeName = $this->input('store_name');
        if (is_string($storeName)) {
            $trimmed = trim($storeName);
            $merged['store_name'] = $trimmed === '' ? null : $trimmed;
        }

        $this->merge($merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:1', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'store_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
