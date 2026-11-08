<?php

namespace App\Http\Resources\Api\V1\Carwash;

use App\Domains\Carwash\Models\AreaType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AreaType */
final class AreaTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'isFixed' => $this->isFixed(),
            'isActive' => (bool) $this->is_active,
            'areasCount' => $this->whenCounted('areas'),
        ];
    }
}
