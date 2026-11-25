<?php

namespace App\Enums;

enum UserRole: string
{
    case CUSTOMER = 'customer';
    case PARENT = 'parent';
    case STUDENT = 'student';
    case STAFF = 'staff';
    case ADMIN = 'admin';

    public function isParentEquivalent(): bool
    {
        return in_array($this, [self::PARENT, self::CUSTOMER], true);
    }

    public function isStudent(): bool
    {
        return $this === self::STUDENT;
    }

    public function isStaffOrAdmin(): bool
    {
        return in_array($this, [self::STAFF, self::ADMIN], true);
    }

    public function effectiveRoleValue(): string
    {
        return $this === self::CUSTOMER ? self::PARENT->value : $this->value;
    }
}
