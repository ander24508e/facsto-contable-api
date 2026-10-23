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
            'role' => $this->role->value,
            'isActive' => $this->is_active,
            'joinedAt' => $this->joined_at?->toIso8601String(),
        ];
    }
}
