<?php

namespace Tests\Feature\Catalog;

use App\Domains\Inventory\Models\StockMovement;
use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

final class InventoryTest extends SalesTestCase
{
    public function test_a_product_needs_its_stock_and_every_change_is_a_movement(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $product = ['type' => 'PRODUCT', 'internalCode' => 'BEB-AGU-001', 'name' => 'Agua mineral', 'unit' => 'UND', 'salePrice' => '1.000000', 'costPrice' => '0.40'];

        $this->actingAs($user)->postJson('/api/v1/items', $product)
            ->assertUnprocessable()->assertJsonValidationErrors(['stock'], 'error.details.errors');
        $this->postJson('/api/v1/items', [...$product, 'stock' => $this->productStock($company, '0')])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ITEM_STOCK_REQUIRED');

        $item = $this->postJson('/api/v1/items', [...$product, 'stock' => $this->productStock($company, '50')])->assertCreated()
            ->assertJsonPath('data.stock.quantity', '50.000000')
            ->assertJsonPath('data.costPrice', '0.400000')
            ->json('data');
        $this->patchJson("/api/v1/items/{$item['publicId']}", ['stock' => $this->productStock($company, '45')])->assertOk()
            ->assertJsonPath('data.stock.quantity', '45.000000');

        $this->assertSame(
            [[StockMovement::INITIAL, '50.000000', '50.000000'], [StockMovement::ADJUSTMENT, '-5.000000', '45.000000']],
            StockMovement::query()->orderBy('id')->get()->map(fn ($movement) => [$movement->type, $movement->quantity, $movement->balance])->all(),
        );
    }

    public function test_a_service_has_no_stock_and_names_stop_at_sixty_characters(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'SERVICE', 'internalCode' => 'LAV-001', 'name' => 'Lavado', 'unit' => 'SERVICIO', 'salePrice' => '5'])
            ->assertCreated()->assertJsonPath('data.stock', null);
        $this->postJson('/api/v1/items', ['type' => 'SERVICE', 'internalCode' => 'LAV-002', 'name' => str_repeat('a', 61), 'unit' => 'SERVICIO', 'salePrice' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name'], 'error.details.errors');
    }

    public function test_the_code_is_suggested_from_category_and_name(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $category = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Bebidas'])->assertCreated()->json('data.publicId');

        $this->getJson('/api/v1/items/next-code?name=Água mineral&category='.$category)->assertOk()->assertJsonPath('data.code', 'BEB-AGU-001');
        $this->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'BEB-AGU-001', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $this->productStock($company)])->assertCreated();
        $this->getJson('/api/v1/items/next-code?name=Agua con gas&category='.$category)->assertOk()->assertJsonPath('data.code', 'BEB-AGU-002');
    }

    public function test_wholesale_prices_apply_from_their_quantity_in_the_sale(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->actingAs($user)->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '1.00', 'stock' => $this->productStock($company),
            'priceTiers' => [['minQuantity' => '3', 'price' => '0.90'], ['minQuantity' => '12', 'price' => '0.80']],
        ])->assertCreated()->assertJsonCount(2, 'data.priceTiers')->json('data.publicId');
        $this->putJson("/api/v1/items/{$item}/taxes", ['taxPublicIds' => [$this->companyIva($company, '0')->public_id]])->assertOk();

        $quote = fn (string $quantity) => $this->postJson('/api/v1/sales/quote', ['lines' => [['itemPublicId' => $item, 'quantity' => $quantity]], 'paymentMethods' => []])
            ->assertOk()->json('data.lines.0.unitPrice');
        $this->assertSame('1.000000', $quote('2'));
        $this->assertSame('0.900000', $quote('3'));
        $this->assertSame('0.800000', $quote('12'));

        $this->patchJson("/api/v1/items/{$item}", ['priceTiers' => [['minQuantity' => '1', 'price' => '0.50']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['priceTiers.0.minQuantity'], 'error.details.errors');
    }

    public function test_low_stock_shows_on_the_dashboard_and_filters_the_list(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'POCO', 'name' => 'Poco', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $this->productStock($company, '5')])->assertCreated();
        $this->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'MUCHO', 'name' => 'Mucho', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $this->productStock($company, '6')])->assertCreated();

        $this->getJson('/api/v1/items?stock=low')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.internalCode', 'POCO');
        $action = collect($this->getJson('/api/v1/dashboard/actions')->assertOk()->json('data'))->firstWhere('code', 'LOW_STOCK');
        $this->assertSame(1, $action['count']);
    }

    public function test_an_item_without_sales_is_deleted_with_its_stock_and_one_in_a_sale_is_kept(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'BORRAR', 'name' => 'Borrar', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $this->productStock($company)])
            ->assertCreated()->json('data.publicId');

        $this->deleteJson("/api/v1/items/{$item}")->assertNoContent();
        $this->assertDatabaseMissing('items', ['public_id' => $item]);
        $this->assertDatabaseCount('item_stocks', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'item.deleted', 'resource_public_id' => $item]);

        $sold = $this->makeSale($user, $company)->details()->first()->item;
        $this->deleteJson("/api/v1/items/{$sold->public_id}")->assertStatus(409)->assertJsonPath('error.code', 'ITEM_IN_USE');
        $this->assertDatabaseHas('items', ['id' => $sold->id]);
    }

    public function test_units_start_with_the_usual_ones_and_one_warehouse_per_establishment(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->getJson('/api/v1/units')->assertOk()->assertJsonCount(6, 'data');
        $this->postJson('/api/v1/units', ['name' => ' galón '])->assertCreated()->assertJsonPath('data.name', 'GALÓN');
        $this->postJson('/api/v1/units', ['name' => 'Galón'])->assertStatus(409)->assertJsonPath('error.code', 'UNIT_DUPLICATE');

        $establishment = $company->establishments()->create(['code' => '002', 'name' => 'Norte', 'address' => 'Quito']);
        $warehouse = $this->postJson('/api/v1/warehouses', ['establishmentPublicId' => $establishment->public_id, 'name' => 'Bodega Norte'])->assertCreated()->json('data.publicId');
        $this->postJson('/api/v1/warehouses', ['establishmentPublicId' => $establishment->public_id, 'name' => 'Otra'])->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_ALREADY_EXISTS');
        $this->patchJson("/api/v1/warehouses/{$warehouse}", ['name' => 'Bodega Norte 2'])->assertOk()->assertJsonPath('data.name', 'Bodega Norte 2');
        $this->getJson("/api/v1/warehouses/{$warehouse}")->assertOk()->assertJsonPath('data.establishmentCode', '002');
        $this->getJson('/api/v1/warehouses?establishment='.$establishment->public_id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_warehouses_module_counts_products_and_keeps_a_stocked_warehouse_active(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $stock = $this->productStock($company, '40');
        $item = $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $stock])
            ->assertCreated()->json('data.publicId');

        $this->getJson('/api/v1/warehouses?status=')->assertOk()
            ->assertJsonPath('data.0.productsCount', 1)
            ->assertJsonPath('data.0.unitsTotal', '40.000000')
            ->assertJsonPath('data.0.isActive', true);
        $this->getJson('/api/v1/items?warehouse='.$stock['warehousePublicId'])->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/warehouses/{$stock['warehousePublicId']}/deactivate")->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_IN_USE');
        $this->patchJson("/api/v1/items/{$item}", ['stock' => [...$stock, 'quantity' => '0']])->assertOk();
        $this->postJson("/api/v1/warehouses/{$stock['warehousePublicId']}/deactivate")->assertOk()->assertJsonPath('data.isActive', false);
        $this->getJson('/api/v1/warehouses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/warehouses?status=INACTIVE')->assertOk()->assertJsonCount(1, 'data');
    }
}
