<?php

namespace App\Services;

use App\Enums\StudentCardStatus;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentCardService
{
    /**
     * @return Collection<int, StudentCard>
     */
    public function listForUser(User $user): Collection
    {
        $cards = $this->queryUserCards($user);

        if ($cards->isEmpty() && $this->shouldAutoProvisionDemoCard()) {
            DB::transaction(function () use ($user): void {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $alreadyHasCard = StudentCard::query()
                    ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
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
            ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
            ->firstOrFail();
    }

    /**
     * @return Collection<int, StudentCard>
     */
    private function queryUserCards(User $user): Collection
    {
        return StudentCard::query()
            ->with(['student', 'activeBankLink', 'spendingLimit'])
            ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
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
}
