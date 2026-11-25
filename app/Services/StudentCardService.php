<?php

namespace App\Services;

use App\Contracts\BankingGatewayInterface;
use App\Enums\BankLinkStatus;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\OtpVerification;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentCardService
{
    public function __construct(
        private readonly BankingGatewayInterface $gateway,
    ) {}

    /**
     * @return Collection<int, StudentCard>
     */
    public function listForUser(User $user): Collection
    {
        $cards = $this->queryUserCards($user);

        $roleEnum = $user->role instanceof UserRole
            ? $user->role
            : (UserRole::tryFrom((string) $user->role) ?? UserRole::CUSTOMER);

        if ($cards->isEmpty() && $roleEnum->isParentEquivalent() && $this->shouldAutoProvisionDemoCard()) {
            DB::transaction(function () use ($user): void {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $alreadyHasCard = StudentCard::query()
                    ->whereHas('student', fn ($q) => $q->accessibleBy($user))
                    ->exists();

                if (! $alreadyHasCard) {
                    $this->provisionDemoCard($user);
                }
            });

            return $this->queryUserCards($user);
        }

        return $cards;
    }

    public function findForUserOrFail(User $user, int $cardId): StudentCard
    {
        return StudentCard::query()
            ->with(['student', 'activeBankLink', 'spendingLimit'])
            ->whereKey($cardId)
            ->whereHas('student', fn ($q) => $q->accessibleBy($user))
            ->firstOrFail();
    }

    /**
     * @return Collection<int, StudentCard>
     */
    private function queryUserCards(User $user): Collection
    {
        return StudentCard::query()
            ->with(['student', 'activeBankLink', 'spendingLimit'])
            ->whereHas('student', fn ($q) => $q->accessibleBy($user))
            ->orderBy('id')
            ->get();
    }

    private function shouldAutoProvisionDemoCard(): bool
    {
        return app()->environment('local')
            && (bool) config('services.demo.login_enabled', false)
            && (bool) config('services.banking.auto_provision_demo_card', false);
    }

    private function provisionDemoCard(User $user): StudentCard
    {
        $student = Student::create([
            'user_id' => $user->id,
            'student_code' => 'HS-'.now()->format('Y').'-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT).'-'.Str::upper(Str::random(3)),
            'full_name' => 'Titkul Student',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A2',
            'avatar_url' => null,
            'is_active' => true,
        ]);

        return StudentCard::create([
            'student_id' => $student->id,
            'card_code' => Str::upper(Str::random(6)),
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
    }

    public function updateCardStatusByAdmin(StudentCard $card, StudentCardStatus $nextStatus): StudentCard
    {
        return DB::transaction(function () use ($card, $nextStatus): StudentCard {
            // Canonical lock order: student_cards -> bank_links -> otp_verifications
            /** @var StudentCard $lockedCard */
            $lockedCard = StudentCard::query()
                ->whereKey($card->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCard->status === StudentCardStatus::REVOKED && $nextStatus !== StudentCardStatus::REVOKED) {
                throw ValidationException::withMessages([
                    'status' => ['Thẻ đã thu hồi (REVOKED) không thể kích hoạt lại.'],
                ]);
            }

            if ($lockedCard->status === $nextStatus) {
                return $lockedCard->load(['student', 'activeBankLink', 'spendingLimit']);
            }

            if ($nextStatus === StudentCardStatus::REVOKED) {
                $now = now();

                /** @var Collection<int, BankLink> $openLinks */
                $openLinks = BankLink::query()
                    ->where('student_card_id', $lockedCard->id)
                    ->whereIn('status', [BankLinkStatus::ACTIVE->value, BankLinkStatus::PENDING_OTP->value])
                    ->lockForUpdate()
                    ->get();

                $linkIds = $openLinks->pluck('id');

                foreach ($openLinks as $link) {
                    if ($link->status === BankLinkStatus::ACTIVE) {
                        $this->gateway->unlinkAutoDebit((string) $link->provider_reference);
                        $link->update([
                            'status' => BankLinkStatus::UNLINKED,
                            'unlinked_at' => $now,
                        ]);
                    } else {
                        $link->update(['status' => BankLinkStatus::EXPIRED]);
                    }
                }

                if ($linkIds->isNotEmpty()) {
                    OtpVerification::query()
                        ->whereIn('bank_link_id', $linkIds)
                        ->whereNull('verified_at')
                        ->whereNull('invalidated_at')
                        ->update(['invalidated_at' => $now]);
                }
            }

            $lockedCard->update(['status' => $nextStatus]);

            return $lockedCard->load(['student', 'activeBankLink', 'spendingLimit']);
        });
    }
}
