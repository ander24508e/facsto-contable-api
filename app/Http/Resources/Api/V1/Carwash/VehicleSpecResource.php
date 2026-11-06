<?php

namespace App\Http\Resources\Api\V1\Carwash;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A vehicle specification: brand, model and type (the type sets the price), with how many vehicles use it. */
final class VehicleSpecResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'brand' => ['publicId' => $this->brand?->public_id, 'name' => $this->brand?->name],
            'model' => $this->name,
            'type' => ['publicId' => $this->type?->public_id, 'name' => $this->type?->name],
            'isActive' => (bool) $this->is_active,
            'vehiclesCount' => (int) ($this->vehicles_count ?? 0),
        ];
    }
}
