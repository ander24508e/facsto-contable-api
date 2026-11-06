<?php

namespace App\Http\Resources\Api\V1\Carwash;

use App\Domains\Carwash\Services\ItemPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A price per vehicle: its specification, the Inventario item invoiced (price read live) and its supplies. */
final class ServicePriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $spec = $this->spec;
        $ref = fn ($model) => $model ? ['publicId' => $model->public_id, 'name' => $model->name] : null;

        return [
            'publicId' => $this->public_id,
            'servicePublicId' => $this->service?->public_id,
            'spec' => $spec ? [
                'publicId' => $spec->public_id,
                'brand' => $ref($spec->brand),
                'model' => $spec->name,
                'type' => $ref($spec->type),
                'isActive' => (bool) $spec->is_active,
            ] : null,
            'vehicleType' => $ref($spec?->type),
            'item' => [
                'publicId' => $this->item?->public_id,
                'code' => $this->item?->internal_code,
                'name' => $this->item?->name,
                'isActive' => (bool) $this->item?->is_active,
            ],
            'salePrice' => $this->item?->sale_price,
            'priceIncludesTax' => (bool) $this->item?->price_includes_tax,
            'price' => $this->item ? app(ItemPrice::class)->final($this->item) : null,
            'durationMinutes' => $this->duration_minutes,
            'effectiveDurationMinutes' => $this->duration_minutes ?? $this->service?->duration_minutes,
            'description' => $this->description,
            'supplies' => $this->whenLoaded('supplies', fn () => $this->supplies->map(fn ($supply): array => [
                'item' => [
                    'publicId' => $supply->item?->public_id,
                    'code' => $supply->item?->internal_code,
                    'name' => $supply->item?->name,
                    'unit' => $supply->item?->unit,
                    'contentQuantity' => $supply->item?->content_quantity,
                    'contentUnit' => $supply->item?->content_unit,
                    'isActive' => (bool) $supply->item?->is_active,
                ],
                'quantity' => $supply->quantity,
                'measure' => $supply->measure,
            ])->values()),
            'isActive' => (bool) $this->is_active,
        ];
    }
}
