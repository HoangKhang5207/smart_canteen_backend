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
        $role = $this->role instanceof UserRole ? $this->role->value : (string) $this->role;
        $phone = is_string($this->phone) && trim($this->phone) !== '' ? trim($this->phone) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $role,
            'zalo_user_id' => $this->zalo_user_id,
            'phone' => $phone,
            'phone_masked' => $phone !== null ? app(DataMaskingService::class)->maskPhoneNumber($phone) : null,
            'has_verified_phone' => $phone !== null && $this->phone_verified_at !== null,
        ];
    }
}
