<?php

namespace App\Http\Resources\Api\V1\Carwash;

use App\Domains\Carwash\Services\ItemPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of Tarifas: its Inventario item (name, codes, category, IVA, price, photo and status are read live from
 * it), the base duration and, for a service, its prices per vehicle; for a product, where it is used as a supply.
 */
final class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->item;

        return [
            'publicId' => $this->public_id,
            'type' => $item?->type?->value,
            'item' => [
                'publicId' => $item?->public_id,
                'code' => $item?->internal_code,
                'auxiliaryCode' => $item?->auxiliary_code,
                'barcode' => $item?->catalogMetadata?->barcode,
                'unit' => $item?->unit,
                'contentQuantity' => $item?->content_quantity,
                'contentUnit' => $item?->content_unit,
                'salePrice' => $item?->sale_price,
                'costPrice' => $item?->cost_price,
                'priceIncludesTax' => (bool) $item?->price_includes_tax,
                'price' => $item ? app(ItemPrice::class)->final($item) : null,
            ],
            'name' => $item?->name,
            'description' => $item?->description,
            'category' => $item?->category ? ['publicId' => $item->category->public_id, 'name' => $item->category->name] : null,
            'taxes' => $item ? $item->taxes->map(fn ($tax): array => ['publicId' => $tax->public_id, 'name' => $tax->name, 'rate' => $tax->definition?->fixed_percentage])->values() : [],
            'hasImage' => $item?->image_path !== null,
            'durationMinutes' => $this->duration_minutes,
            'commissionType' => $this->commission_type,
            'commissionValue' => $this->commission_value === null ? null : (string) $this->commission_value,
            'areaType' => $this->areaType ? ['publicId' => $this->areaType->public_id, 'name' => $this->areaType->name] : null,
            'isActive' => (bool) $item?->is_active,
            'pricesCount' => $this->whenCounted('prices'),
            'usagesCount' => $this->whenCounted('usages'),
            'prices' => ServicePriceResource::collection($this->whenLoaded('prices')),
            'usages' => $this->whenLoaded('usages', fn () => $this->usages->map(fn ($usage): array => [
                'servicePricePublicId' => $usage->price?->public_id,
                'serviceName' => $usage->price?->service?->item?->name,
                'spec' => $usage->price?->spec ? [
                    'publicId' => $usage->price->spec->public_id,
                    'brand' => $usage->price->spec->brand?->name,
                    'model' => $usage->price->spec->name,
                    'type' => $usage->price->spec->type?->name,
                ] : null,
                'quantity' => $usage->quantity,
                'measure' => $usage->measure,
            ])->values()),
        ];
    }
}
