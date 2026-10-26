<?php

namespace App\Domains\Sri\Mappers;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use LogicException;

final class SriEmissionTypeMapper
{
    public function map(EmissionType|string $type): string
    {
        $value = $type instanceof EmissionType ? $type->value : $type;

        return match ($value) {
            'NORMAL' => '1',
            default => throw new LogicException('Unsupported SRI emission type.'),
        };
    }
}
