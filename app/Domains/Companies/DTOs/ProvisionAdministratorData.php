<?php

namespace App\Domains\Companies\DTOs;

final readonly class ProvisionAdministratorData
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
