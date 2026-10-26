<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SriTaxDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'taxType' => $this->tax_type->value,
            'sriCode' => $this->sri_tax_code,
            'percentageCode' => $this->sri_percentage_code,
            'calculationType' => $this->calculation_type->value,
            'percentage' => $this->fixed_percentage,
            'specificRate' => $this->specific_rate,
            'specificUnit' => $this->specific_unit,
            'calculationSupported' => $this->calculation_supported,
            'validFrom' => $this->valid_from->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
            'legalBasis' => $this->legal_basis,
            'isCurrent' => $this->isCurrentOn(today()),
        ];
    }
}
