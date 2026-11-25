<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        $role = $user->role instanceof UserRole
            ? $user->role
            : (UserRole::tryFrom((string) $user->role) ?? UserRole::CUSTOMER);

        if ($role->isStaffOrAdmin()) {
            return true;
        }

        if ($order->user_id === $user->id) {
            return true;
        }

        if ($order->student_card_id !== null) {
            $student = $order->relationLoaded('studentCard')
                ? $order->studentCard?->student
                : $order->studentCard()->with('student')->first()?->student;

            if ($student !== null && $student->is_active) {
                if ($role === UserRole::STUDENT && (int) $student->student_user_id === (int) $user->id) {
                    return true;
                }
                if ($role->isParentEquivalent() && (int) $student->user_id === (int) $user->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
