<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@smartcanteen.local'],
            [
                'name' => 'Quản trị viên Căn tin (Demo)',
                'password' => 'Admin@123456',
                'role' => UserRole::ADMIN,
                'zalo_user_id' => null,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'staff@smartcanteen.local'],
            [
                'name' => 'Nhân viên Bếp (Demo)',
                'password' => 'Staff@123456',
                'role' => UserRole::STAFF,
                'zalo_user_id' => null,
            ],
        );
    }
}
