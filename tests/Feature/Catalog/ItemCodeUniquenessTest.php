<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class ItemCodeUniquenessTest extends CatalogTestCase
{
    public function test_internal_code_is_unique_within_company(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->createItemThroughApi($user);
        $this->actingAs($user)->postJson('/api/v1/items', [
            'type' => 'SERVICE', 'internalCode' => 'ITEM-001', 'name' => 'Duplicado', 'unit' => 'UND', 'salePrice' => '1.00',
        ])->assertStatus(409)->assertJsonPath('error.code', 'ITEM_DUPLICATE_CODE');
    }
}
