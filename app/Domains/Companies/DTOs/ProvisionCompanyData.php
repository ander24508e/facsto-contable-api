<?php

namespace App\Domains\Companies\DTOs;

/**
 * Data needed to activate a subscribed company. Today it comes from the
 * facsto:empresa:crear command; in F10 Condore Platform will send it in a
 * signed event, so this shape mirrors that future contract.
 *
 * It carries no fiscal data on purpose: RUC, razón social, address, contact
 * and emission structure are always entered by the client in the welcome flow.
 */
final readonly class ProvisionCompanyData
{
    public function __construct(
        public string $slug,
        public ProvisionAdministratorData $operator,
        public ?ProvisionAdministratorData $clientAdmin,
    ) {}
}
