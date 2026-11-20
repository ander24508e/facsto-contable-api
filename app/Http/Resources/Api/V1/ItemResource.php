<?php

namespace App\Http\Resources\Api\V1;

use App\Domains\Inventory\Services\StockReservations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['publicId' => $this->public_id, 'updatedAt' => $this->updated_at?->toIso8601String(), 'type' => $this->type->value, 'internalCode' => $this->internal_code,
            'auxiliaryCode' => $this->auxiliary_code, 'name' => $this->name, 'description' => $this->description,
            'unit' => $this->unit, 'salePrice' => $this->sale_price, 'priceIncludesTax' => (bool) $this->price_includes_tax, 'isActive' => $this->is_active,
            'hasImage' => $this->image_path !== null,
            'costPrice' => $this->cost_price,
            'additionalDetail' => $this->additional_detail,
            'contentQuantity' => $this->content_quantity,
            'contentUnit' => $this->content_unit,
            'managedBy' => $this->managed_by,
            // The main warehouse is its first row; `quantity` adds every warehouse and `warehouses` has each one.
            'stock' => $this->whenLoaded('stocks', function () {
                $rows = $this->stocks->sortBy('id')->values();
                $stock = $rows->first();

                if ($stock === null) {
                    return null;
                }
                $total = bcadd((string) $rows->sum('quantity'), '0', 6);
                $reserved = app(StockReservations::class)->forItems($this->company_id, [$this->id])[$this->id] ?? '0';

                return [
                    'quantity' => $total,
                    // Units held by open proformas (yellow) and what a sale may still take.
                    'reserved' => $reserved,
                    'available' => bcsub($total, $reserved, 6),
                    'warehousePublicId' => $stock->warehouse->public_id,
                    'warehouseName' => $stock->warehouse->name,
                    'establishmentPublicId' => $stock->warehouse->establishment->public_id,
                    'warehouses' => $rows->map(fn ($row): array => ['publicId' => $row->warehouse->public_id, 'name' => $row->warehouse->name, 'quantity' => (string) $row->quantity])->all(),
                ];
            }),
            'priceTiers' => $this->whenLoaded('priceTiers', fn () => $this->priceTiers->map(fn ($tier): array => ['minQuantity' => $tier->min_quantity, 'price' => $tier->price])->values()),
            'categoryPublicId' => $this->whenLoaded('category', fn () => $this->category?->public_id),
            'categoryName' => $this->whenLoaded('category', fn () => $this->category?->name),
            'barcode' => $this->whenLoaded('catalogMetadata', fn () => $this->catalogMetadata?->barcode),
            'brandPublicId' => $this->whenLoaded('catalogMetadata', fn () => $this->catalogMetadata?->brand?->public_id),
            'brandName' => $this->whenLoaded('catalogMetadata', fn () => $this->catalogMetadata?->brand?->name),
            'finalPrice' => $this->whenLoaded('taxes', fn () => $this->finalPrice()),
            'taxes' => TaxResource::collection($this->whenLoaded('taxes'))];
    }

    /** What the customer pays for one unit: the price itself when it includes taxes, otherwise price + rates. */
    private function finalPrice(): string
    {
        $price = (string) $this->sale_price;
        if (! $this->price_includes_tax) {
            $rate = '0';
            foreach ($this->taxes as $tax) {
                $rate = bcadd($rate, (string) ($tax->definition->fixed_percentage ?? '0'), 6);
            }
            $price = bcdiv(bcmul($price, bcadd('100', $rate, 6), 12), '100', 12);
        }

        // Half-up to cents; prices are never negative.
        return bcadd($price, '0.005', 2);
    }
}
