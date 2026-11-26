<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompanyMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'userPublicId' => $this->user->public_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'updatedAt' => $this->user->updated_at?->toIso8601String(),
            'roles' => $this->roles->map(fn ($role) => [
                'publicId' => $role->public_id,
                'name' => $role->name,
                'isFixed' => $role->is_fixed,
                'isActive' => $role->is_active,
            ])->values(),
            'isSupport' => $this->user->isInstanceSupport(),
            'isAdministrator' => $this->roles->contains('is_fixed', true),
            'isActive' => $this->is_active,
            'joinedAt' => $this->joined_at?->toIso8601String(),
        ];
    }
}
