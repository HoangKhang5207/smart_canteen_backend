<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->role instanceof UserRole ? $this->role->value : (string) $this->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $role,
            'zalo_user_id' => $this->zalo_user_id,
        ];
    }
}
