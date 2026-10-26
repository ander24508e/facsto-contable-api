<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class CreateItemTest extends CatalogTestCase
{
    public function test_company_member_can_create_item_with_decimal_price(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->createItemThroughApi($user);
        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'internal_code' => 'ITEM-001', 'sale_price' => '12.500000']);
    }
}
