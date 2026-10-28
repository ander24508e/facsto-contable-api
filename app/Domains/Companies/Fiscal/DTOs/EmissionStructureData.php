<?php

namespace App\Domains\Companies\Fiscal\DTOs;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;

final readonly class EmissionStructureData
{
    public function __construct(
        public string $establishmentCode,
        public string $establishmentName,
        public ?string $establishmentAddress,
        public string $emissionPointCode,
        public string $emissionPointName,
        public SriEnvironment $environment,
        public EmissionType $emissionType,
    ) {}
}
