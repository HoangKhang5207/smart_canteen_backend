<?php

namespace Database\Seeders;

use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudentCardSeeder extends Seeder
{
    public function run(): void
    {
        $demoPhoneFree = ! User::query()->where('phone', '0901234567')->exists();

        $demoUser = User::firstOrCreate(
            ['zalo_user_id' => 'demo_customer1'],
            array_filter([
                'name' => 'Khách hàng Demo',
                'role' => UserRole::CUSTOMER,
                'phone' => $demoPhoneFree ? '0901234567' : null,
                'phone_verified_at' => $demoPhoneFree ? now() : null,
            ], fn ($v) => $v !== null),
        );

        if ($demoUser->phone === null && ! User::query()->where('phone', '0901234567')->exists()) {
            $demoUser->update([
                'phone' => '0901234567',
                'phone_verified_at' => now(),
            ]);
        }

        $student = Student::firstOrCreate(
            ['student_code' => 'HS-2026-0001'],
            [
                'user_id' => $demoUser->id,
                'full_name' => 'Titkul Student',
                'school_name' => 'THCS Trần Phú',
                'class_name' => '8A2',
                'avatar_url' => null,
                'is_active' => true,
            ],
        );

        StudentCard::firstOrCreate(
            ['card_code' => 'F156BA'],
            [
                'student_id' => $student->id,
                'status' => StudentCardStatus::ACTIVE,
                'issued_at' => now(),
            ],
        );
    }
}
