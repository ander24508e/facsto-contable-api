<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A company tax: official code and percentage come from its SRI definition, never from the company. */
final class TaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = $this->definition;

        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'taxType' => $definition->tax_type->value,
            'sriCode' => $definition->sri_tax_code,
            'percentageCode' => $definition->sri_percentage_code,
            'percentage' => $definition->fixed_percentage,
            'calculationType' => $definition->calculation_type->value,
            'definitionPublicId' => $definition->public_id,
            'officialValidFrom' => $definition->valid_from->toDateString(),
            'officialValidUntil' => $definition->valid_until?->toDateString(),
            'validFrom' => $this->valid_from?->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
            'legalBasis' => $definition->legal_basis,
            'isActive' => $this->is_active,
            'isUsable' => $this->isUsableOn(today()),
        ];
    }
}
