<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FiscalPaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'validFrom' => $this->valid_from?->toDateString(),
            'validUntil' => $this->valid_until?->toDateString(),
        ];
    }
}
