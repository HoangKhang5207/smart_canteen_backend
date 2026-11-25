<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpendingLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'daily_limit' => ['required', 'integer', 'min:0', 'max:5000000', 'multiple_of:1000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'daily_limit.required' => 'Vui lòng nhập hạn mức chi tiêu hằng ngày.',
            'daily_limit.integer' => 'Hạn mức chi tiêu phải là số nguyên VNĐ.',
            'daily_limit.min' => 'Hạn mức chi tiêu không được nhỏ hơn 0 đ.',
            'daily_limit.max' => 'Hạn mức chi tiêu tối đa là 5.000.000 đ/ngày.',
            'daily_limit.multiple_of' => 'Hạn mức chi tiêu phải là bội số của 1.000 đ.',
        ];
    }
}
