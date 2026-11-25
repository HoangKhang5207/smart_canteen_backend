<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'student_user_id',
    'student_code',
    'full_name',
    'school_name',
    'class_name',
    'avatar_url',
    'is_active',
])]
class Student extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(StudentCard::class);
    }

    /**
     * Scope students visible to a Mini App user (parent owns via user_id; student accesses own via student_user_id).
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $role = $user->role instanceof UserRole
            ? $user->role
            : (UserRole::tryFrom((string) $user->role) ?? UserRole::CUSTOMER);

        if ($role === UserRole::STUDENT) {
            return $query->where('student_user_id', $user->id)->where('is_active', true);
        }

        return $query->where('user_id', $user->id)->where('is_active', true);
    }
}
