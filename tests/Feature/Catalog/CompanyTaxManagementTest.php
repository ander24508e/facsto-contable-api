<?php

namespace Tests\Feature\Catalog;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Taxes\Models\SriTaxDefinition;
use App\Models\User;

final class CompanyTaxManagementTest extends CatalogTestCase
{
    public function test_owner_activates_an_official_rate_inside_its_legal_window(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $definition = SriTaxDefinition::query()->create([
            'tax_type' => 'IVA', 'sri_tax_code' => '2', 'sri_percentage_code' => '8', 'name' => 'IVA diferenciado 8%',
            'calculation_type' => 'PERCENTAGE', 'fixed_percentage' => '8.000000', 'calculation_supported' => true,
            'enabled_by_default' => false, 'valid_from' => today()->subDay(), 'valid_until' => today()->addDays(2),
            'legal_basis' => 'Decreto de prueba', 'is_active' => true,
        ]);

        $this->actingAs($owner)->postJson('/api/v1/taxes', ['definitionPublicId' => $definition->public_id])
            ->assertCreated()->assertJsonPath('data.percentageCode', '8')->assertJsonPath('data.percentage', '8.000000');
        $this->assertSame(1, $company->taxes()->where('sri_tax_definition_id', $definition->id)->count());
    }

    public function test_biller_can_consult_but_not_change_company_taxes(): void
    {
        $owner = User::factory()->create();
        $biller = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $biller, CompanyRole::BILLER);
        $tax = $this->companyIva($company, '5');

        $this->actingAs($biller)->getJson('/api/v1/taxes')->assertOk();
        $this->actingAs($biller)->postJson('/api/v1/taxes/'.$tax->public_id.'/deactivate')->assertForbidden();
        $this->actingAs($biller)->postJson('/api/v1/taxes/replacements', [
            'fromPublicId' => $tax->public_id, 'toPublicId' => $this->companyIva($company, '4')->public_id,
        ])->assertForbidden();
    }

    public function test_a_rate_in_use_cannot_be_deactivated_until_products_are_moved(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $item = $this->createItemThroughApi($owner);
        $from = $this->companyIva($company, '5');
        $this->actingAs($owner)->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$from->public_id]])->assertOk();

        $this->actingAs($owner)->postJson('/api/v1/taxes/'.$from->public_id.'/deactivate')
            ->assertConflict()->assertJsonPath('error.code', 'COMPANY_TAX_IN_USE')->assertJsonPath('error.details.itemCount', 1);
    }

    public function test_bulk_replacement_previews_and_moves_products_to_another_rate(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $from = $this->companyIva($company, '5');
        $to = $this->companyIva($company, '4');
        foreach (['A-1', 'A-2'] as $code) {
            $item = $this->createItemThroughApi($owner, $code);
            $this->actingAs($owner)->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$from->public_id]])->assertOk();
        }
        $body = ['fromPublicId' => $from->public_id, 'toPublicId' => $to->public_id];

        $this->actingAs($owner)->postJson('/api/v1/taxes/replacements/preview', $body)->assertOk()->assertJsonPath('data.itemCount', 2);
        $this->actingAs($owner)->postJson('/api/v1/taxes/replacements', $body)->assertOk()->assertJsonPath('data.itemCount', 2);

        $this->assertSame(0, $from->items()->count());
        $this->assertSame(2, $to->items()->count());
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'company_tax.items_replaced']);
        $this->actingAs($owner)->postJson('/api/v1/taxes/'.$from->public_id.'/deactivate')->assertOk()->assertJsonPath('data.isActive', false);
    }

    public function test_replacement_must_stay_within_the_same_tax_type(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $ice = $this->customCompanyTax($company, ['tax_type' => 'ICE', 'sri_tax_code' => '3', 'sri_percentage_code' => '3092', 'name' => 'ICE prueba', 'fixed_percentage' => '0.000000']);

        $this->actingAs($owner)->postJson('/api/v1/taxes/replacements', [
            'fromPublicId' => $this->companyIva($company, '4')->public_id, 'toPublicId' => $ice->public_id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'TAX_REPLACEMENT_INVALID');
    }

    public function test_a_tax_without_implemented_calculation_cannot_be_activated(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner, '1799999999001');
        $definition = SriTaxDefinition::query()->create([
            'tax_type' => 'ICE', 'sri_tax_code' => '3', 'sri_percentage_code' => '3011', 'name' => 'ICE cigarrillos',
            'calculation_type' => 'SPECIFIC', 'specific_rate' => '0.160000', 'specific_unit' => 'UNIDAD', 'calculation_supported' => false,
            'valid_from' => today()->subYear(), 'legal_basis' => 'Ficha técnica SRI v2.34, tabla 18', 'is_active' => true,
        ]);

        $this->actingAs($owner)->postJson('/api/v1/taxes', ['definitionPublicId' => $definition->public_id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'TAX_CALCULATION_NOT_SUPPORTED');
    }
}
