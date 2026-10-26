<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class CategoryIsolationTest extends CatalogTestCase
{
    public function test_a_company_cannot_read_or_change_another_companies_category(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $categoryA = $companyA->categories()->create(['name' => 'A']);
        $categoryB = $companyB->categories()->create(['name' => 'B']);

        $this->actingAs($userA)->getJson('/api/v1/categories')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.publicId', $categoryA->public_id);
        $this->actingAs($userA)->getJson('/api/v1/categories/'.$categoryB->public_id)->assertForbidden();
        $this->actingAs($userA)->patchJson('/api/v1/categories/'.$categoryB->public_id, ['name' => 'Exfiltrada'])->assertForbidden();

        $this->actingAs($userA)->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => 'ITEM-A', 'name' => 'Artículo A', 'unit' => 'UND', 'salePrice' => '10.000000', 'categoryPublicId' => $categoryB->public_id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    }
}
