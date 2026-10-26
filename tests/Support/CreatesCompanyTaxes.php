<?php

namespace Tests\Support;

use App\Domains\Companies\Models\Company;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Models\SriTaxDefinition;
use Database\Seeders\SriCatalogSeeder;

/** Official catalog, fiscal rules and payment methods, exactly as production seeds them. */
trait CreatesCompanyTaxes
{
    protected function seedSriCatalog(): void
    {
        $this->seed(SriCatalogSeeder::class);
    }

    /** The company tax enabled from an official IVA code of table 17 (e.g. '0', '4'). */
    protected function companyIva(Company $company, string $percentageCode): CompanyTax
    {
        return $company->taxes()
            ->whereHas('definition', fn ($query) => $query->where('tax_type', 'IVA')->where('sri_percentage_code', $percentageCode)->whereNull('valid_until'))
            ->with('definition')
            ->firstOrFail();
    }

    /** A test-only official definition (e.g. an unusual rate) enabled for the company. */
    protected function customCompanyTax(Company $company, array $definition): CompanyTax
    {
        $definition = SriTaxDefinition::query()->create($definition + [
            'tax_type' => 'IVA', 'sri_tax_code' => '2', 'calculation_type' => 'PERCENTAGE', 'percentage_editable' => false,
            'calculation_supported' => true, 'enabled_by_default' => false, 'valid_from' => today()->subYear(),
            'legal_basis' => 'Prueba', 'is_active' => true,
        ]);

        return $company->taxes()->create(['sri_tax_definition_id' => $definition->id, 'name' => $definition->name, 'is_active' => true])->load('definition');
    }
}
