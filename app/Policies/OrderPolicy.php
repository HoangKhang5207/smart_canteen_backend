<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if (in_array($user->role, [UserRole::STAFF, UserRole::ADMIN], true)) {
            return true;
        }

        return $order->user_id === $user->id;
    }
}
