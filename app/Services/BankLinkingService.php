<?php

namespace App\Services;

use App\Contracts\BankingGatewayInterface;
use App\Enums\BankLinkStatus;
use App\Enums\OtpPurpose;
use App\Enums\StudentCardStatus;
use App\Exceptions\BankLinkConflictException;
use App\Exceptions\OtpVerificationException;
use App\Models\BankLink;
use App\Models\OtpVerification;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class BankLinkingService
{
    public function __construct(
        private readonly BankingGatewayInterface $gateway,
        private readonly DataMaskingService $masking,
    ) {}

    /**
     * @param  array{
     *     bank_code: string,
     *     account_number: string,
     *     account_holder_name: string,
     *     national_id: string,
     *     phone_number: string,
     *     email?: ?string,
     *     agreed_terms: bool
     * }  $payload
     * @return array<string, mixed>
     */
    public function initiateLink(User $user, int $cardId, array $payload): array
    {
        $bank = $this->gateway->findBank($payload['bank_code']);
        if ($bank === null) {
            throw ValidationException::withMessages([
                'bank_code' => ['Ngân hàng được chọn chưa được hỗ trợ.'],
            ]);
        }

        $ttlSeconds = max(30, (int) config('services.banking.otp_ttl_seconds', 180));
        $cooldownSeconds = max(5, (int) config('services.banking.otp_resend_cooldown_seconds', 30));
        $maxAttempts = max(1, (int) config('services.banking.otp_max_attempts', 5));
        $maxResends = max(1, (int) config('services.banking.otp_max_resends', 3));

        return DB::transaction(function () use (
            $user,
            $cardId,
            $payload,
            $bank,
            $ttlSeconds,
            $cooldownSeconds,
            $maxAttempts,
            $maxResends,
        ): array {
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($cardId)
                ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(StudentCard::class, [$cardId]);
            }

            if ($card->status !== StudentCardStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'card' => ['Thẻ học sinh hiện không ở trạng thái hoạt động nên không thể liên kết ngân hàng.'],
                ]);
            }

            $hasActiveLink = BankLink::query()
                ->where('student_card_id', $card->id)
                ->where('status', BankLinkStatus::ACTIVE->value)
                ->exists();

            if ($hasActiveLink) {
                throw new BankLinkConflictException(
                    'Thẻ học sinh này đã được liên kết với một tài khoản ngân hàng.',
                    'CARD_ALREADY_LINKED',
                );
            }

            // Expire any previous pending OTP sessions on this card (supports OTP-06 edit phone flow)
            $stalePendingIds = BankLink::query()
                ->where('student_card_id', $card->id)
                ->where('status', BankLinkStatus::PENDING_OTP->value)
                ->pluck('id');

            if ($stalePendingIds->isNotEmpty()) {
                BankLink::query()
                    ->whereIn('id', $stalePendingIds)
                    ->update(['status' => BankLinkStatus::EXPIRED->value]);

                OtpVerification::query()
                    ->whereIn('bank_link_id', $stalePendingIds)
                    ->whereNull('verified_at')
                    ->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now()]);
            }

            $accountNumber = trim($payload['account_number']);
            $holderName = mb_strtoupper(trim($payload['account_holder_name']), 'UTF-8');
            $nationalId = trim($payload['national_id']);
            $phoneNumber = trim($payload['phone_number']);
            $email = isset($payload['email']) && trim((string) $payload['email']) !== ''
                ? trim((string) $payload['email'])
                : null;

            $gatewayResult = $this->gateway->initiateAutoDebitLink([
                'bank_code' => $bank['code'],
                'account_number' => $accountNumber,
                'account_holder_name' => $holderName,
                'national_id' => $nationalId,
                'phone_number' => $phoneNumber,
                'email' => $email,
                'card_code' => $card->card_code,
            ]);

            $phoneMasked = $this->masking->maskPhoneNumber($phoneNumber);

            $bankLink = BankLink::create([
                'student_card_id' => $card->id,
                'user_id' => $user->id,
                'bank_code' => $bank['code'],
                'bank_name' => $bank['short_name'],
                'account_number_encrypted' => $accountNumber,
                'account_number_masked' => $this->masking->maskAccountNumber($accountNumber),
                'account_holder_name' => $holderName,
                'national_id_encrypted' => $nationalId,
                'national_id_masked' => $this->masking->maskNationalId($nationalId),
                'phone_number' => $phoneNumber,
                'phone_masked' => $phoneMasked,
                'email' => $email,
                'auto_debit_agreed' => true,
                'auto_debit_agreed_at' => now(),
                'provider_reference' => $gatewayResult['provider_reference'],
                'status' => BankLinkStatus::PENDING_OTP,
            ]);

            $otpCode = $this->generateSixDigitOtp();
            $now = now();

            $otp = OtpVerification::create([
                'user_id' => $user->id,
                'bank_link_id' => $bankLink->id,
                'purpose' => OtpPurpose::BANK_LINK,
                'phone_masked' => $phoneMasked,
                'code_hash' => Hash::make($otpCode),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'resend_count' => 0,
                'max_resends' => $maxResends,
                'last_sent_at' => $now,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ]);

            return $this->formatInitiationResponse(
                bankLink: $bankLink,
                otp: $otp,
                plainOtp: $otpCode,
                ttlSeconds: $ttlSeconds,
                cooldownSeconds: $cooldownSeconds,
            );
        });
    }

    public function verifyOtp(User $user, int $bankLinkId, string $otpCode): StudentCard
    {
        $studentCardId = BankLink::query()
            ->whereKey($bankLinkId)
            ->where('user_id', $user->id)
            ->value('student_card_id');

        if ($studentCardId === null) {
            throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
        }

        try {
            $outcome = DB::transaction(function () use ($user, $studentCardId, $bankLinkId, $otpCode): array {
                // Canonical lock order: student_cards -> bank_links -> otp_verifications
                /** @var StudentCard|null $card */
                $card = StudentCard::query()
                    ->whereKey($studentCardId)
                    ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                    ->lockForUpdate()
                    ->first();

                if ($card === null) {
                    return [
                        'ok' => false,
                        'exception' => (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]),
                    ];
                }

                /** @var BankLink|null $bankLink */
                $bankLink = BankLink::query()
                    ->whereKey($bankLinkId)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($bankLink === null) {
                    return [
                        'ok' => false,
                        'exception' => (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]),
                    ];
                }

                if ($bankLink->status === BankLinkStatus::FAILED) {
                    return [
                        'ok' => false,
                        'exception' => new OtpVerificationException(
                            'Bạn đã nhập sai mã OTP quá số lần cho phép. Vui lòng đăng ký liên kết lại.',
                            'OTP_MAX_ATTEMPTS_EXCEEDED',
                            429,
                            ['remaining_attempts' => 0],
                        ),
                    ];
                }

                if ($bankLink->status !== BankLinkStatus::PENDING_OTP) {
                    $errorCode = $bankLink->status === BankLinkStatus::ACTIVE
                        ? 'BANK_LINK_ALREADY_ACTIVE'
                        : 'BANK_LINK_INVALID_STATE';

                    return [
                        'ok' => false,
                        'exception' => new BankLinkConflictException(
                            'Phiên liên kết ngân hàng không còn ở trạng thái chờ xác thực OTP.',
                            $errorCode,
                        ),
                    ];
                }

                if ($card->status !== StudentCardStatus::ACTIVE) {
                    return [
                        'ok' => false,
                        'exception' => ValidationException::withMessages([
                            'card' => ['Thẻ học sinh hiện không ở trạng thái hoạt động nên không thể hoàn tất liên kết.'],
                        ]),
                    ];
                }

                /** @var OtpVerification|null $otp */
                $otp = OtpVerification::query()
                    ->where('bank_link_id', $bankLink->id)
                    ->where('purpose', OtpPurpose::BANK_LINK->value)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($otp === null || $otp->verified_at !== null) {
                    return [
                        'ok' => false,
                        'exception' => new OtpVerificationException(
                            'Phiên xác thực OTP không hợp lệ. Vui lòng khởi tạo lại.',
                            'OTP_EXPIRED',
                            422,
                        ),
                    ];
                }

                if ($otp->attempts >= $otp->max_attempts) {
                    $bankLink->update(['status' => BankLinkStatus::FAILED]);

                    return [
                        'ok' => false,
                        'exception' => new OtpVerificationException(
                            'Bạn đã nhập sai mã OTP quá số lần cho phép. Vui lòng đăng ký liên kết lại.',
                            'OTP_MAX_ATTEMPTS_EXCEEDED',
                            429,
                            ['remaining_attempts' => 0],
                        ),
                    ];
                }

                if ($otp->invalidated_at !== null || $otp->expires_at->isPast()) {
                    if ($otp->invalidated_at === null) {
                        $otp->update(['invalidated_at' => now()]);
                    }

                    return [
                        'ok' => false,
                        'exception' => new OtpVerificationException(
                            'Mã OTP đã hết hạn. Vui lòng gửi lại mã OTP mới.',
                            'OTP_EXPIRED',
                            422,
                        ),
                    ];
                }

                if (! Hash::check($otpCode, $otp->code_hash)) {
                    $nextAttempts = $otp->attempts + 1;
                    $remainingAttempts = max(0, $otp->max_attempts - $nextAttempts);

                    $updates = ['attempts' => $nextAttempts];
                    if ($nextAttempts >= $otp->max_attempts) {
                        $updates['invalidated_at'] = now();
                    }
                    $otp->update($updates);

                    if ($nextAttempts >= $otp->max_attempts) {
                        $bankLink->update(['status' => BankLinkStatus::FAILED]);

                        return [
                            'ok' => false,
                            'exception' => new OtpVerificationException(
                                'Bạn đã nhập sai mã OTP quá số lần cho phép. Vui lòng đăng ký liên kết lại.',
                                'OTP_MAX_ATTEMPTS_EXCEEDED',
                                429,
                                ['remaining_attempts' => 0],
                            ),
                        ];
                    }

                    return [
                        'ok' => false,
                        'exception' => new OtpVerificationException(
                            sprintf('Mã OTP không chính xác. Bạn còn %d lần thử.', $remainingAttempts),
                            'OTP_INVALID',
                            422,
                            ['remaining_attempts' => $remainingAttempts],
                        ),
                    ];
                }

                $hasActiveLink = BankLink::query()
                    ->where('student_card_id', $card->id)
                    ->where('status', BankLinkStatus::ACTIVE->value)
                    ->exists();

                if ($hasActiveLink) {
                    return [
                        'ok' => false,
                        'exception' => new BankLinkConflictException(
                            'Thẻ học sinh này đã được liên kết với một tài khoản ngân hàng.',
                            'CARD_ALREADY_LINKED',
                        ),
                    ];
                }

                $this->gateway->confirmAutoDebitLink((string) $bankLink->provider_reference);

                $now = now();
                $otp->update(['verified_at' => $now]);
                $bankLink->update([
                    'status' => BankLinkStatus::ACTIVE,
                    'linked_at' => $now,
                ]);

                return [
                    'ok' => true,
                    'card' => $card->load(['student', 'activeBankLink', 'spendingLimit']),
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw new BankLinkConflictException(
                'Thẻ học sinh này đã được liên kết với một tài khoản ngân hàng.',
                'CARD_ALREADY_LINKED',
            );
        }

        if (! $outcome['ok']) {
            throw $outcome['exception'];
        }

        return $outcome['card'];
    }

    /**
     * @return array<string, mixed>
     */
    public function resendOtp(User $user, int $bankLinkId): array
    {
        $studentCardId = BankLink::query()
            ->whereKey($bankLinkId)
            ->where('user_id', $user->id)
            ->value('student_card_id');

        if ($studentCardId === null) {
            throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
        }

        $ttlSeconds = max(30, (int) config('services.banking.otp_ttl_seconds', 180));
        $cooldownSeconds = max(5, (int) config('services.banking.otp_resend_cooldown_seconds', 30));
        $maxAttempts = max(1, (int) config('services.banking.otp_max_attempts', 5));

        return DB::transaction(function () use ($user, $studentCardId, $bankLinkId, $ttlSeconds, $cooldownSeconds, $maxAttempts): array {
            // Canonical lock order: student_cards -> bank_links -> otp_verifications
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($studentCardId)
                ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
            }

            /** @var BankLink|null $bankLink */
            $bankLink = BankLink::query()
                ->whereKey($bankLinkId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($bankLink === null) {
                throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
            }

            if ($bankLink->status === BankLinkStatus::FAILED) {
                throw new OtpVerificationException(
                    'Phiên liên kết đã bị khóa do nhập sai OTP quá số lần cho phép. Vui lòng đăng ký liên kết lại.',
                    'OTP_MAX_ATTEMPTS_EXCEEDED',
                    429,
                    ['remaining_attempts' => 0],
                );
            }

            if ($bankLink->status !== BankLinkStatus::PENDING_OTP) {
                throw new BankLinkConflictException(
                    'Không thể gửi lại OTP cho phiên liên kết không còn ở trạng thái chờ xác thực.',
                    'BANK_LINK_INVALID_STATE',
                );
            }

            if ($card->status !== StudentCardStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'card' => ['Thẻ học sinh hiện không ở trạng thái hoạt động nên không thể gửi lại OTP.'],
                ]);
            }

            /** @var OtpVerification|null $latestOtp */
            $latestOtp = OtpVerification::query()
                ->where('bank_link_id', $bankLink->id)
                ->where('purpose', OtpPurpose::BANK_LINK->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latestOtp === null) {
                throw new OtpVerificationException(
                    'Không tìm thấy phiên OTP để gửi lại.',
                    'OTP_EXPIRED',
                    422,
                );
            }

            if ($latestOtp->verified_at !== null) {
                throw new BankLinkConflictException(
                    'Phiên liên kết đã được xác thực trước đó.',
                    'BANK_LINK_INVALID_STATE',
                );
            }

            if ($latestOtp->attempts >= $latestOtp->max_attempts) {
                throw new OtpVerificationException(
                    'Phiên liên kết đã bị khóa do nhập sai OTP quá số lần cho phép. Vui lòng đăng ký liên kết lại.',
                    'OTP_MAX_ATTEMPTS_EXCEEDED',
                    429,
                    ['remaining_attempts' => 0],
                );
            }

            if ($latestOtp->resend_count >= $latestOtp->max_resends) {
                throw new OtpVerificationException(
                    'Bạn đã vượt quá số lần gửi lại mã OTP cho phép. Vui lòng khởi tạo lại liên kết.',
                    'OTP_MAX_RESENDS_EXCEEDED',
                    429,
                    ['remaining_resends' => 0],
                );
            }

            $now = now();
            $elapsed = $now->getTimestamp() - $latestOtp->last_sent_at->getTimestamp();
            if ($elapsed < $cooldownSeconds) {
                $retryAfter = max(1, $cooldownSeconds - $elapsed);

                throw new OtpVerificationException(
                    sprintf('Vui lòng đợi %d giây trước khi gửi lại mã OTP.', $retryAfter),
                    'OTP_RESEND_COOLDOWN',
                    429,
                    ['retry_after_seconds' => $retryAfter],
                );
            }

            if ($latestOtp->invalidated_at === null) {
                $latestOtp->update(['invalidated_at' => $now]);
            }

            $otpCode = $this->generateSixDigitOtp();

            $newOtp = OtpVerification::create([
                'user_id' => $user->id,
                'bank_link_id' => $bankLink->id,
                'purpose' => OtpPurpose::BANK_LINK,
                'phone_masked' => $bankLink->phone_masked,
                'code_hash' => Hash::make($otpCode),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'resend_count' => $latestOtp->resend_count + 1,
                'max_resends' => $latestOtp->max_resends,
                'last_sent_at' => $now,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ]);

            return $this->formatInitiationResponse(
                bankLink: $bankLink,
                otp: $newOtp,
                plainOtp: $otpCode,
                ttlSeconds: $ttlSeconds,
                cooldownSeconds: $cooldownSeconds,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateUnlink(User $user, int $bankLinkId): array
    {
        $studentCardId = BankLink::query()
            ->whereKey($bankLinkId)
            ->where('user_id', $user->id)
            ->value('student_card_id');

        if ($studentCardId === null) {
            throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
        }

        $ttlSeconds = max(30, (int) config('services.banking.otp_ttl_seconds', 180));
        $cooldownSeconds = max(5, (int) config('services.banking.otp_resend_cooldown_seconds', 30));
        $maxAttempts = max(1, (int) config('services.banking.otp_max_attempts', 5));
        $maxResends = max(1, (int) config('services.banking.otp_max_resends', 3));

        return DB::transaction(function () use (
            $user,
            $studentCardId,
            $bankLinkId,
            $ttlSeconds,
            $cooldownSeconds,
            $maxAttempts,
            $maxResends,
        ): array {
            // Canonical lock order: student_cards -> bank_links -> otp_verifications
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($studentCardId)
                ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
            }

            /** @var BankLink|null $bankLink */
            $bankLink = BankLink::query()
                ->whereKey($bankLinkId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($bankLink === null) {
                throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
            }

            if ($bankLink->status !== BankLinkStatus::ACTIVE) {
                throw new BankLinkConflictException(
                    'Tài khoản ngân hàng hiện không ở trạng thái đang liên kết.',
                    'BANK_LINK_NOT_ACTIVE',
                );
            }

            $now = now();

            /** @var OtpVerification|null $latestUnlinkOtp */
            $latestUnlinkOtp = OtpVerification::query()
                ->where('bank_link_id', $bankLink->id)
                ->where('purpose', OtpPurpose::BANK_UNLINK->value)
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latestUnlinkOtp !== null) {
                $elapsed = $now->getTimestamp() - $latestUnlinkOtp->last_sent_at->getTimestamp();
                if ($elapsed < $cooldownSeconds) {
                    $retryAfter = max(1, $cooldownSeconds - $elapsed);

                    throw new OtpVerificationException(
                        sprintf('Vui lòng đợi %d giây trước khi yêu cầu mã OTP hủy liên kết mới.', $retryAfter),
                        'OTP_RESEND_COOLDOWN',
                        429,
                        ['retry_after_seconds' => $retryAfter],
                    );
                }
            }

            // Invalidate any previous unverified unlink OTPs for this bank link
            OtpVerification::query()
                ->where('bank_link_id', $bankLink->id)
                ->where('purpose', OtpPurpose::BANK_UNLINK->value)
                ->whereNull('verified_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now]);

            $otpCode = $this->generateSixDigitOtp();

            $otp = OtpVerification::create([
                'user_id' => $user->id,
                'bank_link_id' => $bankLink->id,
                'purpose' => OtpPurpose::BANK_UNLINK,
                'phone_masked' => $bankLink->phone_masked,
                'code_hash' => Hash::make($otpCode),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'resend_count' => 0,
                'max_resends' => $maxResends,
                'last_sent_at' => $now,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ]);

            return $this->formatInitiationResponse(
                bankLink: $bankLink,
                otp: $otp,
                plainOtp: $otpCode,
                ttlSeconds: $ttlSeconds,
                cooldownSeconds: $cooldownSeconds,
            );
        });
    }

    public function verifyUnlinkOtp(User $user, int $bankLinkId, string $otpCode): StudentCard
    {
        $studentCardId = BankLink::query()
            ->whereKey($bankLinkId)
            ->where('user_id', $user->id)
            ->value('student_card_id');

        if ($studentCardId === null) {
            throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
        }

        $outcome = DB::transaction(function () use ($user, $studentCardId, $bankLinkId, $otpCode): array {
            // Canonical lock order: student_cards -> bank_links -> otp_verifications
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($studentCardId)
                ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                return [
                    'ok' => false,
                    'exception' => (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]),
                ];
            }

            /** @var BankLink|null $bankLink */
            $bankLink = BankLink::query()
                ->whereKey($bankLinkId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($bankLink === null) {
                return [
                    'ok' => false,
                    'exception' => (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]),
                ];
            }

            if ($bankLink->status !== BankLinkStatus::ACTIVE) {
                return [
                    'ok' => false,
                    'exception' => new BankLinkConflictException(
                        'Tài khoản ngân hàng hiện không ở trạng thái đang liên kết.',
                        'BANK_LINK_NOT_ACTIVE',
                    ),
                ];
            }

            /** @var OtpVerification|null $otp */
            $otp = OtpVerification::query()
                ->where('bank_link_id', $bankLink->id)
                ->where('purpose', OtpPurpose::BANK_UNLINK->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->verified_at !== null) {
                return [
                    'ok' => false,
                    'exception' => new OtpVerificationException(
                        'Phiên xác thực OTP hủy liên kết không hợp lệ. Vui lòng khởi tạo lại.',
                        'OTP_EXPIRED',
                        422,
                    ),
                ];
            }

            if ($otp->attempts >= $otp->max_attempts) {
                if ($otp->invalidated_at === null) {
                    $otp->update(['invalidated_at' => now()]);
                }

                return [
                    'ok' => false,
                    'exception' => new OtpVerificationException(
                        'Bạn đã nhập sai mã OTP quá số lần cho phép. Vui lòng khởi tạo lại yêu cầu hủy liên kết.',
                        'OTP_MAX_ATTEMPTS_EXCEEDED',
                        429,
                        ['remaining_attempts' => 0],
                    ),
                ];
            }

            if ($otp->invalidated_at !== null || $otp->expires_at->isPast()) {
                if ($otp->invalidated_at === null) {
                    $otp->update(['invalidated_at' => now()]);
                }

                return [
                    'ok' => false,
                    'exception' => new OtpVerificationException(
                        'Mã OTP hủy liên kết đã hết hạn. Vui lòng gửi lại mã OTP mới.',
                        'OTP_EXPIRED',
                        422,
                    ),
                ];
            }

            if (! Hash::check($otpCode, $otp->code_hash)) {
                $nextAttempts = $otp->attempts + 1;
                $remainingAttempts = max(0, $otp->max_attempts - $nextAttempts);

                $updates = ['attempts' => $nextAttempts];
                if ($nextAttempts >= $otp->max_attempts) {
                    $updates['invalidated_at'] = now();
                }
                $otp->update($updates);

                if ($nextAttempts >= $otp->max_attempts) {
                    return [
                        'ok' => false,
                        'exception' => new OtpVerificationException(
                            'Bạn đã nhập sai mã OTP quá số lần cho phép. Vui lòng khởi tạo lại yêu cầu hủy liên kết.',
                            'OTP_MAX_ATTEMPTS_EXCEEDED',
                            429,
                            ['remaining_attempts' => 0],
                        ),
                    ];
                }

                return [
                    'ok' => false,
                    'exception' => new OtpVerificationException(
                        sprintf('Mã OTP không chính xác. Bạn còn %d lần thử.', $remainingAttempts),
                        'OTP_INVALID',
                        422,
                        ['remaining_attempts' => $remainingAttempts],
                    ),
                ];
            }

            $this->gateway->unlinkAutoDebit((string) $bankLink->provider_reference);

            $now = now();
            $otp->update(['verified_at' => $now]);
            $bankLink->update([
                'status' => BankLinkStatus::UNLINKED,
                'unlinked_at' => $now,
            ]);

            return [
                'ok' => true,
                'card' => $card->load(['student', 'activeBankLink', 'spendingLimit']),
            ];
        });

        if (! $outcome['ok']) {
            throw $outcome['exception'];
        }

        return $outcome['card'];
    }

    /**
     * @return array<string, mixed>
     */
    public function resendUnlinkOtp(User $user, int $bankLinkId): array
    {
        $studentCardId = BankLink::query()
            ->whereKey($bankLinkId)
            ->where('user_id', $user->id)
            ->value('student_card_id');

        if ($studentCardId === null) {
            throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
        }

        $ttlSeconds = max(30, (int) config('services.banking.otp_ttl_seconds', 180));
        $cooldownSeconds = max(5, (int) config('services.banking.otp_resend_cooldown_seconds', 30));
        $maxAttempts = max(1, (int) config('services.banking.otp_max_attempts', 5));

        return DB::transaction(function () use (
            $user,
            $studentCardId,
            $bankLinkId,
            $ttlSeconds,
            $cooldownSeconds,
            $maxAttempts,
        ): array {
            // Canonical lock order: student_cards -> bank_links -> otp_verifications
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($studentCardId)
                ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
            }

            /** @var BankLink|null $bankLink */
            $bankLink = BankLink::query()
                ->whereKey($bankLinkId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($bankLink === null) {
                throw (new ModelNotFoundException)->setModel(BankLink::class, [$bankLinkId]);
            }

            if ($bankLink->status !== BankLinkStatus::ACTIVE) {
                throw new BankLinkConflictException(
                    'Tài khoản ngân hàng hiện không ở trạng thái đang liên kết.',
                    'BANK_LINK_NOT_ACTIVE',
                );
            }

            /** @var OtpVerification|null $latestOtp */
            $latestOtp = OtpVerification::query()
                ->where('bank_link_id', $bankLink->id)
                ->where('purpose', OtpPurpose::BANK_UNLINK->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latestOtp === null) {
                throw new OtpVerificationException(
                    'Không tìm thấy phiên OTP hủy liên kết để gửi lại.',
                    'OTP_EXPIRED',
                    422,
                );
            }

            if ($latestOtp->verified_at !== null) {
                throw new BankLinkConflictException(
                    'Phiên hủy liên kết đã được xác thực trước đó.',
                    'BANK_LINK_NOT_ACTIVE',
                );
            }

            if ($latestOtp->attempts >= $latestOtp->max_attempts) {
                throw new OtpVerificationException(
                    'Phiên OTP hủy liên kết đã bị khóa do nhập sai quá số lần cho phép. Vui lòng khởi tạo lại yêu cầu hủy liên kết.',
                    'OTP_MAX_ATTEMPTS_EXCEEDED',
                    429,
                    ['remaining_attempts' => 0],
                );
            }

            if ($latestOtp->resend_count >= $latestOtp->max_resends) {
                throw new OtpVerificationException(
                    'Bạn đã vượt quá số lần gửi lại mã OTP cho phép. Vui lòng khởi tạo lại yêu cầu hủy liên kết.',
                    'OTP_MAX_RESENDS_EXCEEDED',
                    429,
                    ['remaining_resends' => 0],
                );
            }

            $now = now();
            $elapsed = $now->getTimestamp() - $latestOtp->last_sent_at->getTimestamp();
            if ($elapsed < $cooldownSeconds) {
                $retryAfter = max(1, $cooldownSeconds - $elapsed);

                throw new OtpVerificationException(
                    sprintf('Vui lòng đợi %d giây trước khi gửi lại mã OTP.', $retryAfter),
                    'OTP_RESEND_COOLDOWN',
                    429,
                    ['retry_after_seconds' => $retryAfter],
                );
            }

            if ($latestOtp->invalidated_at === null) {
                $latestOtp->update(['invalidated_at' => $now]);
            }

            $otpCode = $this->generateSixDigitOtp();

            $newOtp = OtpVerification::create([
                'user_id' => $user->id,
                'bank_link_id' => $bankLink->id,
                'purpose' => OtpPurpose::BANK_UNLINK,
                'phone_masked' => $bankLink->phone_masked,
                'code_hash' => Hash::make($otpCode),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'resend_count' => $latestOtp->resend_count + 1,
                'max_resends' => $latestOtp->max_resends,
                'last_sent_at' => $now,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ]);

            return $this->formatInitiationResponse(
                bankLink: $bankLink,
                otp: $newOtp,
                plainOtp: $otpCode,
                ttlSeconds: $ttlSeconds,
                cooldownSeconds: $cooldownSeconds,
            );
        });
    }

    private function generateSixDigitOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInitiationResponse(
        BankLink $bankLink,
        OtpVerification $otp,
        string $plainOtp,
        int $ttlSeconds,
        int $cooldownSeconds,
    ): array {
        $otpPayload = [
            'expires_in_seconds' => $ttlSeconds,
            'resend_cooldown_seconds' => $cooldownSeconds,
            'max_attempts' => $otp->max_attempts,
            'remaining_attempts' => max(0, $otp->max_attempts - $otp->attempts),
            'max_resends' => $otp->max_resends,
            'remaining_resends' => max(0, $otp->max_resends - $otp->resend_count),
        ];

        if ($this->shouldExposeDebugOtp()) {
            $otpPayload['debug_otp'] = $plainOtp;
        }

        return [
            'bank_link_id' => $bankLink->id,
            'student_card_id' => $bankLink->student_card_id,
            'purpose' => $otp->purpose->value,
            'status' => $bankLink->status->value,
            'bank_code' => $bankLink->bank_code,
            'bank_name' => $bankLink->bank_name,
            'account_number_masked' => $bankLink->account_number_masked,
            'phone_masked' => $bankLink->phone_masked,
            'otp' => $otpPayload,
        ];
    }

    private function shouldExposeDebugOtp(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('services.banking.expose_debug_otp', false);
    }
}
