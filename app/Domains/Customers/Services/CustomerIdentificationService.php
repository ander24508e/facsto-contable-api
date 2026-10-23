<?php

namespace App\Domains\Customers\Services;

use App\Domains\Customers\Enums\IdentificationType;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class CustomerIdentificationService
{
    public function normalize(IdentificationType $type, string $identification): string
    {
        $value = strtoupper(trim($identification));
        $valid = match ($type) {
            IdentificationType::RUC => preg_match('/^\d{13}$/', $value) === 1,
            IdentificationType::CEDULA => preg_match('/^\d{10}$/', $value) === 1,
            IdentificationType::PASSPORT, IdentificationType::FOREIGN_IDENTIFICATION => preg_match('/^[A-Z0-9-]{3,20}$/', $value) === 1,
            IdentificationType::FINAL_CONSUMER => $value === '9999999999999',
        };

        if (! $valid) {
            throw new ApiException(ApiErrorCode::INVALID_CUSTOMER_IDENTIFICATION, 'La identificación no tiene un formato válido para el tipo seleccionado.', 422);
        }

        return $value;
    }
}
