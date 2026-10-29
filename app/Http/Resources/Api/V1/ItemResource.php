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
            'categoryPublicId' => $this->whenLoaded('category', fn () => $this->category?->public_id),
            'categoryName' => $this->whenLoaded('category', fn () => $this->category?->name),
            'taxes' => TaxResource::collection($this->whenLoaded('taxes'))];
    }
}
