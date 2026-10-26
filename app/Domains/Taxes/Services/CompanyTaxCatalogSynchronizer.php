<?php

namespace App\Domains\Taxes\Services;

use App\Domains\Companies\Models\Company;
use App\Domains\Taxes\Models\SriTaxDefinition;
use Illuminate\Support\Collection;

/**
 * Distributes the official catalog: every company gets the definitions marked enabled_by_default
 * (current or announced for a future date). Conditional rates stay for each company to activate.
 */
final class CompanyTaxCatalogSynchronizer
{
    public function syncCompany(Company $company): void
    {
        foreach ($this->defaultDefinitions() as $definition) {
            $company->taxes()->firstOrCreate(
                ['sri_tax_definition_id' => $definition->id],
                ['name' => $definition->name, 'is_active' => true],
            );
        }
    }

    public function syncAllCompanies(): void
    {
        Company::query()->each(fn (Company $company) => $this->syncCompany($company));
    }

    /** @return Collection<int, SriTaxDefinition> */
    private function defaultDefinitions(): Collection
    {
        return SriTaxDefinition::query()
            ->where('is_active', true)
            ->where('enabled_by_default', true)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->get();
    }
}
