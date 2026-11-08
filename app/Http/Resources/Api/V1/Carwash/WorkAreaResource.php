<?php

namespace App\Http\Resources\Api\V1\Carwash;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A work area: its jobs, how many vehicles it serves at once, its hours and today's reservations. */
final class WorkAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'types' => $this->whenLoaded('types', fn () => $this->types->map(fn ($type) => ['publicId' => $type->public_id, 'name' => $type->name])->values()),
            'capacity' => $this->capacity,
            'opensAt' => substr((string) $this->opens_at, 0, 5),
            'closesAt' => substr((string) $this->closes_at, 0, 5),
            'isActive' => (bool) $this->is_active,
            'bookingsToday' => $this->whenHas('bookings_today', fn () => (int) $this->bookings_today),
        ];
    }
}
