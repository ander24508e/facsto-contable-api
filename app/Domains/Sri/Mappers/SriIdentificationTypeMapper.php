<?php

namespace App\Domains\Sri\Mappers;

use App\Domains\Customers\Enums\IdentificationType;
use LogicException;

final class SriIdentificationTypeMapper
{
    public function map(IdentificationType|string $type): string
    {
        $value = $type instanceof IdentificationType ? $type->value : $type;

        return match ($value) {
            'RUC' => '04',
            'CEDULA' => '05',
            'PASSPORT' => '06',
            'FINAL_CONSUMER' => '07',
            'FOREIGN_IDENTIFICATION' => '08',
            default => throw new LogicException('Unsupported SRI identification type.'),
        };
    }
}
