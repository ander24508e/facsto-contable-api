<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class SyncItemTaxesTest extends CatalogTestCase
{
    public function test_item_can_be_assigned_a_current_company_iva_rate(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user);
        $tax = $this->companyIva($company, '4');

        $this->actingAs($user)->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$tax->public_id]])
            ->assertOk()->assertJsonPath('data.taxes.0.publicId', $tax->public_id)
            ->assertJsonPath('data.taxes.0.percentageCode', '4')
            ->assertJsonPath('data.taxes.0.percentage', '15.000000');
    }

    public function test_expired_rate_cannot_be_assigned(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user);
        $tax = $this->customCompanyTax($company, [
            'sri_percentage_code' => '2', 'name' => 'IVA 12%', 'fixed_percentage' => '12.000000',
            'valid_from' => today()->subYears(3), 'valid_until' => today()->subDay(),
        ]);

        $this->actingAs($user)->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$tax->public_id]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'TAX_NOT_AVAILABLE');
    }

    public function test_a_rate_of_another_company_cannot_be_assigned(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $otherCompany = $this->createCompany(User::factory()->create(), '1790016919001');
        $item = $this->createItemThroughApi($user);

        $this->actingAs($user)->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$this->companyIva($otherCompany, '4')->public_id]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'TAX_NOT_AVAILABLE');
    }
}
