<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['publicId' => $this->public_id, 'taxType' => $this->tax_type, 'sriCode' => $this->sri_code,
            'percentageCode' => $this->percentage_code, 'name' => $this->name, 'percentage' => $this->percentage,
            'validFrom' => $this->valid_from?->toDateString(), 'validUntil' => $this->valid_until?->toDateString(), 'isActive' => $this->is_active];
    }
}
