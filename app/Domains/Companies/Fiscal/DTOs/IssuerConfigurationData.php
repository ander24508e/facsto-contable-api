<?php

namespace App\Domains\Companies\Fiscal\DTOs;

final readonly class IssuerConfigurationData
{
    public function __construct(
        public string $ruc,
        public string $legalName,
        public ?string $commercialName,
        public string $mainAddress,
        public ?string $email,
        public ?string $phone,
    ) {}
}
