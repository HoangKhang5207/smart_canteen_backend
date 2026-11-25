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
        $demoUser = User::firstOrCreate(
            ['zalo_user_id' => 'demo_customer1'],
            [
                'name' => 'Khách hàng Demo',
                'role' => UserRole::CUSTOMER,
            ],
        );

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
