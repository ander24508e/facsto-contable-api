<?php

namespace App\Http\Resources\Api\V1\Carwash;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Vehicle types, brands and other named entries: name and state. */
final class NamedEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
