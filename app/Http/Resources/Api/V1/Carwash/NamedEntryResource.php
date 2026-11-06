<?php

namespace App\Http\Resources\Api\V1\Carwash;

use App\Domains\Carwash\Models\Washer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Vehicle types, brands and washers: name and state (plus the phone of a washer). */
final class NamedEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'isActive' => (bool) $this->is_active,
            'phone' => $this->when($this->resource instanceof Washer, fn () => $this->phone),
        ];
    }
}
