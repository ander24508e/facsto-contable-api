<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['publicId' => $this->public_id, 'type' => $this->type->value, 'internalCode' => $this->internal_code,
            'auxiliaryCode' => $this->auxiliary_code, 'name' => $this->name, 'description' => $this->description,
            'unit' => $this->unit, 'salePrice' => $this->sale_price, 'priceIncludesTax' => (bool) $this->price_includes_tax, 'isActive' => $this->is_active,
            'hasImage' => $this->image_path !== null,
            'costPrice' => $this->cost_price,
            'additionalDetail' => $this->additional_detail,
            // A product lives in one warehouse (one per establishment).
            'stock' => $this->whenLoaded('stocks', function () {
                $stock = $this->stocks->first();

                return $stock === null ? null : [
                    'quantity' => $stock->quantity,
                    'warehousePublicId' => $stock->warehouse->public_id,
                    'warehouseName' => $stock->warehouse->name,
                    'establishmentPublicId' => $stock->warehouse->establishment->public_id,
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
