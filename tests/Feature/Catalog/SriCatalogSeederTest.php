<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Models\FiscalRule;
use App\Domains\Taxes\Models\SriTaxDefinition;
use App\Models\User;
use Database\Seeders\SriCatalogSeeder;

final class SriCatalogSeederTest extends CatalogTestCase
{
    public function test_official_catalog_is_loaded_once_and_distributed_to_existing_companies(): void
    {
        $company = $this->createCompany(User::factory()->create(), '1799999999001');
        CompanyTax::query()->delete();

        $this->seed(SriCatalogSeeder::class);
        $this->seed(SriCatalogSeeder::class);

        $current = SriTaxDefinition::query()->where('tax_type', 'IVA')->whereNull('valid_until')->orderBy('sri_percentage_code')->pluck('sri_percentage_code')->all();
        $this->assertSame(['0', '4', '5', '6', '7'], $current);
        $this->assertSame(7, SriTaxDefinition::query()->count());
        $this->assertSame(2, FiscalRule::query()->count());
        $this->assertSame(['01', '15', '16', '17', '18', '19', '20', '21'], FiscalPaymentMethod::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(5, $company->taxes()->count());
    }

    public function test_official_codes_and_percentages_are_never_editable_through_the_api(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $tax = $this->companyIva($company, '4');

        // There is no endpoint that edits a company tax or an official definition.
        $this->actingAs($user)->patchJson('/api/v1/taxes/'.$tax->public_id, ['percentage' => '16.000000'])->assertNotFound();
        $this->actingAs($user)->patchJson('/api/v1/tax-definitions/'.$tax->definition->public_id, ['fixedPercentage' => '16.000000'])->assertNotFound();
        $this->assertSame('15.000000', $tax->definition->refresh()->fixed_percentage);
    }
}
