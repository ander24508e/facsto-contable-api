<?php

namespace App\Http\Resources\Api\V1\Carwash;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($model) => $model ? ['publicId' => $model->public_id, 'name' => $model->name] : null;

        return [
            'publicId' => $this->public_id,
            'plate' => $this->plate,
            'customer' => [
                'publicId' => $this->customer?->public_id,
                'name' => $this->customer?->legal_name,
                'identification' => $this->customer?->identification,
            ],
            'type' => $ref($this->type),
            'brand' => $ref($this->brand),
            'model' => $ref($this->model),
            'color' => $this->color,
            'year' => $this->year,
            'notes' => $this->notes,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
