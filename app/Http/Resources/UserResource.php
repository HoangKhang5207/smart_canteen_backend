<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Services\DataMaskingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roleEnum = $this->role instanceof UserRole
            ? $this->role
            : (UserRole::tryFrom((string) $this->role) ?? UserRole::CUSTOMER);
        $phone = is_string($this->phone) && trim($this->phone) !== '' ? trim($this->phone) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $roleEnum->value,
            'effective_role' => $roleEnum->effectiveRoleValue(),
            'can_manage_canteen' => $roleEnum->isParentEquivalent(),
            'zalo_user_id' => $this->zalo_user_id,
            'phone' => $phone,
            'phone_masked' => $phone !== null ? app(DataMaskingService::class)->maskPhoneNumber($phone) : null,
            'has_verified_phone' => $phone !== null && $this->phone_verified_at !== null,
        ];
    }
}
