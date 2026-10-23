<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class UpdateItemTest extends CatalogTestCase
{
    public function test_item_can_be_updated_without_converting_price_to_float(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user, $this->companyHeader($company));

        $this->actingAs($user)->withHeaders($this->companyHeader($company))->patchJson('/api/v1/items/'.$item['publicId'], ['salePrice' => '0.123456'])
            ->assertOk()->assertJsonPath('data.salePrice', '0.123456');
        $this->assertDatabaseHas('items', ['public_id' => $item['publicId'], 'sale_price' => '0.123456']);
    }
}
