<?php

namespace App\Domains\Carwash\Services;

use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Placa sin espacios ni guiones y en mayúsculas («pbc-1234» → «PBC1234»), igual que la tabla 29 del SRI.
 * Admite autos (ABC1234), motos (AB123C), diplomáticas, extranjeras y maquinaria (5 a 10 caracteres).
 */
final class PlateNormalizer
{
    public function normalize(string $plate): string
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-.]/', '', $plate));
        if (! preg_match('/^[A-Z0-9]{5,10}$/', $normalized) || ! preg_match('/\d/', $normalized)) {
            throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_PLATE_INVALID, 'La placa no es válida.', 422, ['errors' => ['plate' => ['La placa no es válida.']]]);
        }

        return $normalized;
    }
}
