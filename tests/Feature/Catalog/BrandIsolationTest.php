<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class BrandIsolationTest extends CatalogTestCase
{
    public function test_a_company_cannot_read_or_assign_another_companies_brand(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $brandA = $companyA->brands()->create(['name' => 'Marca A']);
        $brandB = $companyB->brands()->create(['name' => 'Marca B']);

        $this->actingAs($userA)->getJson('/api/v1/brands')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.publicId', $brandA->public_id);
        $this->getJson('/api/v1/brands/'.$brandB->public_id)->assertForbidden();
        $this->postJson('/api/v1/items', [
            'type' => 'SERVICE', 'internalCode' => 'SERVICE-A', 'name' => 'Servicio A', 'unit' => 'SERVICIO',
            'salePrice' => '1.00', 'brandPublicId' => $brandB->public_id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'BRAND_NOT_FOUND');
    }
}
