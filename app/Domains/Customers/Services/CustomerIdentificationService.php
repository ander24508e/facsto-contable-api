<?php

namespace App\Domains\Customers\Services;

use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;
use App\Domains\Customers\Enums\IdentificationType;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Tabla 6 of the SRI: each buyer identification type with its own check. A cédula and a RUC are
 * verified with the módulo 10 / structural rules, so a typing error never reaches the XML.
 */
final class CustomerIdentificationService
{
    public const FINAL_CONSUMER_IDENTIFICATION = '9999999999999';

    public function __construct(private readonly EcuadorRucValidator $validator) {}

    public function normalize(IdentificationType $type, string $identification): string
    {
        $value = strtoupper(trim($identification));
        if (! $this->isValid($type, $value)) {
            throw new ApiException(ApiErrorCode::INVALID_CUSTOMER_IDENTIFICATION, $this->message($type), 422);
        }

        return $value;
    }

    public function isValid(IdentificationType $type, string $value): bool
    {
        return match ($type) {
            IdentificationType::RUC => $this->validator->isStructurallyValid($value),
            IdentificationType::CEDULA => $this->validator->isValidCedula($value),
            IdentificationType::PASSPORT => preg_match('/^[A-Z0-9]{3,20}$/', $value) === 1,
            IdentificationType::FOREIGN_IDENTIFICATION => preg_match('/^[A-Z0-9-]{3,20}$/', $value) === 1,
            IdentificationType::FINAL_CONSUMER => $value === self::FINAL_CONSUMER_IDENTIFICATION,
        };
    }

    /** The type a typed identification most likely is: 13 nines, a valid RUC, a valid cédula, or none. */
    public function detect(string $identification): ?IdentificationType
    {
        $value = strtoupper(trim($identification));

        return match (true) {
            $value === self::FINAL_CONSUMER_IDENTIFICATION => IdentificationType::FINAL_CONSUMER,
            $this->validator->isStructurallyValid($value) => IdentificationType::RUC,
            $this->validator->isValidCedula($value) => IdentificationType::CEDULA,
            default => null,
        };
    }

    private function message(IdentificationType $type): string
    {
        return match ($type) {
            IdentificationType::RUC => 'El RUC no es válido: revisa sus 13 dígitos.',
            IdentificationType::CEDULA => 'La cédula no es válida: revisa sus 10 dígitos.',
            IdentificationType::PASSPORT => 'El pasaporte debe tener entre 3 y 20 letras o números, sin espacios ni símbolos.',
            IdentificationType::FINAL_CONSUMER => 'La identificación de consumidor final es 9999999999999.',
            default => 'La identificación debe tener entre 3 y 20 letras, números o guiones.',
        };
    }
}
