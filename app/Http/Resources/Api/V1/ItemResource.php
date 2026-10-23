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
            'unit' => $this->unit, 'salePrice' => $this->sale_price, 'isActive' => $this->is_active,
            'taxes' => TaxResource::collection($this->whenLoaded('taxes'))];
    }
}
