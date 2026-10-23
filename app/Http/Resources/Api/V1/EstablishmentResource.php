<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EstablishmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'isActive' => $this->is_active,
            'isMain' => $this->is_main,
        ];
    }
}
