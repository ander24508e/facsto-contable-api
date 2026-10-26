<?php

namespace App\Domains\Companies\DTOs;

/**
 * Data needed to activate a subscribed company. Today it comes from the
 * facsto:empresa:crear command; in F10 Condore Platform will send it in a
 * signed event, so this shape mirrors that future contract.
 */
final readonly class ProvisionCompanyData
{
    public function __construct(
        public string $slug,
        public string $ruc,
        public string $mainAddress,
        public ?string $legalName,
        public ?string $email,
        public ?string $phone,
        public ProvisionAdministratorData $operator,
        public ?ProvisionAdministratorData $clientAdmin,
    ) {}
}
