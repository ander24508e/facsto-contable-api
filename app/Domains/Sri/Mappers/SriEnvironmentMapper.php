<?php

namespace App\Domains\Sri\Mappers;

use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use LogicException;

final class SriEnvironmentMapper
{
    public function map(SriEnvironment|string $environment): string
    {
        $value = $environment instanceof SriEnvironment ? $environment->value : $environment;

        return match ($value) {
            'TEST' => '1',
            'PRODUCTION' => '2',
            default => throw new LogicException('Unsupported SRI environment.'),
        };
    }
}
