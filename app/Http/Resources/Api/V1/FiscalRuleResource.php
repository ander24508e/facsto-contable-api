<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FiscalRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code->value,
            'valueType' => $this->value_type,
            'value' => $this->value_type === 'DECIMAL' ? bcadd((string) $this->value_decimal, '0', 2) : $this->value_string,
            'validFrom' => $this->valid_from->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
            'legalBasis' => $this->legal_basis,
            'isCurrent' => $this->is_active && $this->valid_from->lte(today()) && ($this->valid_until === null || $this->valid_until->gte(today())),
        ];
    }
}
