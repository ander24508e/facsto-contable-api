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
        $this->assertSame(['01', '15', '16', '17', '18', '19', '20', '21'], FiscalPaymentMethod::query()->where('is_credit', false)->where('is_transfer', false)->orderBy('code')->pluck('code')->all());
        // CREDITO, fixed for every company, sends code 01 with its term.
        $this->assertSame(['01'], FiscalPaymentMethod::query()->where('is_credit', true)->where('name', 'CREDITO')->pluck('code')->all());
        // TRANSFERENCIA sends code 20 and asks for the reference.
        $this->assertSame(['20'], FiscalPaymentMethod::query()->where('is_transfer', true)->where('name', 'TRANSFERENCIA')->pluck('code')->all());
        $this->assertSame(5, $company->taxes()->count());
    }

    public function test_official_codes_and_percentages_are_never_editable_through_the_api(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $tax = $this->companyIva($company, '4');

        // A company tax only renames itself or narrows its validity; the official percentage is ignored.
        $this->actingAs($user)->patchJson('/api/v1/taxes/'.$tax->public_id, ['name' => 'IVA tienda', 'percentage' => '16.000000'])->assertOk();
        $this->actingAs($user)->patchJson('/api/v1/tax-definitions/'.$tax->definition->public_id, ['fixedPercentage' => '16.000000'])->assertNotFound();
        $this->assertSame('15.000000', $tax->definition->refresh()->fixed_percentage);
    }
}
