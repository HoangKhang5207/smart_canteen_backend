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

        // Demo student account for 4-role RBAC testing (read-only canteen + purchase within limit)
        $studentPhoneFree = ! User::query()->where('phone', '0901234568')->exists();
        $demoStudentUser = User::firstOrCreate(
            ['zalo_user_id' => 'demo_student1'],
            array_filter([
                'name' => 'Titkul Student (Học sinh)',
                'role' => UserRole::STUDENT,
                'phone' => $studentPhoneFree ? '0901234568' : null,
                'phone_verified_at' => $studentPhoneFree ? now() : null,
            ], fn ($v) => $v !== null),
        );

        if ($demoStudentUser->phone === null && ! User::query()->where('phone', '0901234568')->exists()) {
            $demoStudentUser->update([
                'phone' => '0901234568',
                'phone_verified_at' => now(),
            ]);
        }

        $student = Student::firstOrCreate(
            ['student_code' => 'HS-2026-0001'],
            [
                'user_id' => $demoUser->id,
                'student_user_id' => $demoStudentUser->id,
                'full_name' => 'Titkul Student',
                'school_name' => 'THCS Trần Phú',
                'class_name' => '8A2',
                'avatar_url' => null,
                'is_active' => true,
            ],
        );

        if ($student->student_user_id === null) {
            $student->update(['student_user_id' => $demoStudentUser->id]);
        }

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
