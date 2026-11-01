<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class ItemCatalogMetadataTest extends CatalogTestCase
{
    public function test_item_barcode_and_brand_are_saved_and_barcode_is_company_unique(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $brand = $company->brands()->create(['name' => 'Marca Uno']);
        $payload = [
            'type' => 'SERVICE', 'internalCode' => 'SERVICE-001', 'name' => 'Servicio', 'unit' => 'SERVICIO',
            'salePrice' => '1.00', 'brandPublicId' => $brand->public_id, 'barcode' => 'BC-001',
        ];

        $item = $this->actingAs($user)->postJson('/api/v1/items', $payload)->assertCreated()
            ->assertJsonPath('data.brandPublicId', $brand->public_id)
            ->assertJsonPath('data.brandName', 'Marca Uno')
            ->assertJsonPath('data.barcode', 'BC-001')->json('data');
        $this->assertDatabaseHas('item_catalog_metadata', ['company_id' => $company->id, 'item_id' => $company->items()->where('public_id', $item['publicId'])->value('id'), 'brand_id' => $brand->id, 'barcode' => 'BC-001']);

        $this->postJson('/api/v1/items', [...$payload, 'internalCode' => 'SERVICE-002'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ITEM_DUPLICATE_BARCODE');
        $this->patchJson('/api/v1/items/'.$item['publicId'], ['barcode' => 'BC-002'])
            ->assertOk()->assertJsonPath('data.barcode', 'BC-002');
    }

    public function test_a_product_without_barcode_gets_an_internal_ean13(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $stock = $this->productStock($company, '3');
        $product = fn (string $code): array => ['type' => 'PRODUCT', 'internalCode' => $code, 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $stock];

        $this->actingAs($user)->getJson('/api/v1/items/next-barcode')->assertOk()->assertJsonPath('data.barcode', '2000000000015');
        $this->postJson('/api/v1/items', $product('AGUA-1'))->assertCreated()->assertJsonPath('data.barcode', '2000000000015');
        $this->postJson('/api/v1/items', $product('AGUA-2'))->assertCreated()->assertJsonPath('data.barcode', '2000000000022');
        // A factory code is kept, and a service is not given a barcode.
        $this->postJson('/api/v1/items', [...$product('AGUA-3'), 'barcode' => '7861234567898'])->assertCreated()->assertJsonPath('data.barcode', '7861234567898');
        $this->postJson('/api/v1/items', ['type' => 'SERVICE', 'internalCode' => 'SRV-1', 'name' => 'Asesoría', 'unit' => 'SERVICIO', 'salePrice' => '5'])
            ->assertCreated()->assertJsonPath('data.barcode', null);
    }
}
