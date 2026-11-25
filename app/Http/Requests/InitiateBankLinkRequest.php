<?php

namespace App\Http\Requests;

use App\Contracts\BankingGatewayInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateBankLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bank_code' => is_string($this->input('bank_code'))
                ? strtoupper(trim($this->input('bank_code')))
                : $this->input('bank_code'),
            'account_number' => is_string($this->input('account_number'))
                ? trim($this->input('account_number'))
                : $this->input('account_number'),
            'account_holder_name' => is_string($this->input('account_holder_name'))
                ? trim($this->input('account_holder_name'))
                : $this->input('account_holder_name'),
            'national_id' => is_string($this->input('national_id'))
                ? trim($this->input('national_id'))
                : $this->input('national_id'),
            'phone_number' => is_string($this->input('phone_number'))
                ? trim($this->input('phone_number'))
                : $this->input('phone_number'),
            'email' => is_string($this->input('email')) && trim($this->input('email')) === ''
                ? null
                : (is_string($this->input('email')) ? trim($this->input('email')) : $this->input('email')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(BankingGatewayInterface $gateway): array
    {
        $banks = $gateway->listSupportedBanks();
        $bankCodes = array_map(static fn (array $bank): string => $bank['code'], $banks);
        $selectedBank = is_string($this->input('bank_code'))
            ? $gateway->findBank($this->input('bank_code'))
            : null;

        $minLen = $selectedBank['account_min_length'] ?? 6;
        $maxLen = $selectedBank['account_max_length'] ?? 20;

        return [
            'bank_code' => ['required', 'string', Rule::in($bankCodes)],
            'account_number' => ['required', 'string', 'regex:/^\d+$/', "min:{$minLen}", "max:{$maxLen}"],
            'account_holder_name' => ['required', 'string', 'min:2', 'max:150'],
            'national_id' => ['required', 'string', 'digits:12'],
            'phone_number' => ['required', 'string', 'regex:/^0[35789]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'agreed_terms' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_code.required' => 'Vui lòng chọn ngân hàng liên kết.',
            'bank_code.in' => 'Ngân hàng được chọn chưa nằm trong danh sách hỗ trợ.',
            'account_number.required' => 'Vui lòng nhập số tài khoản ngân hàng.',
            'account_number.regex' => 'Số tài khoản ngân hàng chỉ được chứa chữ số.',
            'account_number.min' => 'Số tài khoản phải có ít nhất :min chữ số.',
            'account_number.max' => 'Số tài khoản không được vượt quá :max chữ số.',
            'account_holder_name.required' => 'Vui lòng nhập tên chủ tài khoản.',
            'account_holder_name.min' => 'Tên chủ tài khoản phải có ít nhất 2 ký tự.',
            'national_id.required' => 'Vui lòng nhập số căn cước công dân (CCCD).',
            'national_id.digits' => 'Số CCCD phải gồm đúng 12 chữ số.',
            'phone_number.required' => 'Vui lòng nhập số điện thoại nhận mã OTP.',
            'phone_number.regex' => 'Số điện thoại không đúng định dạng di động Việt Nam (10 chữ số).',
            'email.email' => 'Địa chỉ email không đúng định dạng.',
            'agreed_terms.accepted' => 'Bạn cần đồng ý điều khoản đăng ký trích nợ tự động để tiếp tục.',
        ];
    }
}
