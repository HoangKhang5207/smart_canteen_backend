<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyBankLinkOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('otp_code'))) {
            $this->merge([
                'otp_code' => trim($this->input('otp_code')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'otp_code' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'otp_code.required' => 'Vui lòng nhập mã OTP gồm 6 chữ số.',
            'otp_code.digits' => 'Mã OTP phải gồm đúng 6 chữ số.',
        ];
    }
}
