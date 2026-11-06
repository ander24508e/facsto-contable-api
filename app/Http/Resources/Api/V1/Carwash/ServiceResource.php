<?php

namespace App\Http\Resources\Api\V1\Carwash;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category ? ['publicId' => $this->category->public_id, 'name' => $this->category->name] : null,
            'tax' => $this->tax ? ['publicId' => $this->tax->public_id, 'name' => $this->tax->name] : null,
            'hasImage' => $this->image_path !== null,
            'durationMinutes' => $this->duration_minutes,
            'isFeatured' => (bool) $this->is_featured,
            'isBookable' => (bool) $this->is_bookable,
            'isActive' => (bool) $this->is_active,
            'pricesCount' => $this->whenCounted('prices'),
            'prices' => ServicePriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
