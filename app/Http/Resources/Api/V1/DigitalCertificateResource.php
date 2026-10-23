<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DigitalCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'issuedTo' => $this->issued_to,
            'issuer' => $this->issuer,
            'serialNumber' => $this->serial_number,
            'validFrom' => $this->valid_from?->toIso8601String(),
            'validUntil' => $this->valid_until?->toIso8601String(),
            'status' => $this->status->value,
            'isActive' => $this->is_active,
        ];
    }
}
