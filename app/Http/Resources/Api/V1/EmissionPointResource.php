<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EmissionPointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'establishmentPublicId' => $this->establishment->public_id,
            'establishmentCode' => $this->establishment->code,
            'code' => $this->code,
            'name' => $this->name,
            'isActive' => $this->is_active,
            'isDefault' => $this->is_default,
        ];
    }
}
