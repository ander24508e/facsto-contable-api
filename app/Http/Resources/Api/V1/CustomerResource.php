<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'identificationType' => $this->identification_type->value,
            'identification' => $this->identification,
            'legalName' => $this->legal_name,
            'commercialName' => $this->commercial_name,
            'email' => $this->email,
            'additionalEmails' => $this->additional_emails ?? [],
            'phone' => $this->phone,
            'address' => $this->address,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
