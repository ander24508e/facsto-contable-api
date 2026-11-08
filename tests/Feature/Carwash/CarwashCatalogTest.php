<?php

namespace Tests\Feature\Carwash;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;

/** Vehicles of FACSTO customers, Tarifas read from Inventario, prices per vehicle that are Inventario items, and supplies. */
final class CarwashCatalogTest extends CarwashTestCase
{
    public function test_vehicle_belongs_to_a_facsto_customer_and_plates_are_unique(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);

        $this->actingAs($owner)->postJson(self::API.'/vehicles', [
            'plate' => 'gbc-0123', 'customerPublicId' => $setup['customer']->public_id, 'vehicleTypePublicId' => $setup['type']->public_id, 'color' => 'Rojo',
        ])->assertCreated()->assertJsonPath('data.plate', 'GBC0123')->assertJsonPath('data.customer.name', 'María Pérez');

        $this->actingAs($owner)->postJson(self::API.'/vehicles', [
            'plate' => 'GBC 0123', 'customerPublicId' => $setup['customer']->public_id, 'vehicleTypePublicId' => $setup['type']->public_id,
        ])->assertConflict()->assertJsonPath('error.code', 'CARWASH_VEHICLE_PLATE_DUPLICATE');
        $this->actingAs($owner)->postJson(self::API.'/vehicles', [
            'plate' => 'ABCDEF', 'customerPublicId' => $setup['customer']->public_id, 'vehicleTypePublicId' => $setup['type']->public_id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_VEHICLE_PLATE_INVALID');

        $this->actingAs($owner)->getJson(self::API.'/vehicles/lookup?plate=gbc.0123')->assertOk()
            ->assertJsonPath('data.vehicle.plate', 'GBC0123')->assertJsonPath('data.openWorkOrder', null);
    }

    public function test_inventory_items_of_the_turned_on_categories_show_in_tarifas_and_get_prices_per_vehicle(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $iva = $this->companyIva($company, '4');
        $washes = Category::query()->create(['company_id' => $company->id, 'name' => 'Lavadas Automotrices', 'is_active' => true]);
        $drinks = Category::query()->create(['company_id' => $company->id, 'name' => 'Bebidas', 'is_active' => true]);
        $universal = Item::query()->create(['company_id' => $company->id, 'category_id' => $washes->id, 'internal_code' => 'LAV-UNI', 'type' => 'SERVICE', 'name' => 'Lavada Universal', 'unit' => 'UND', 'sale_price' => '30', 'is_active' => true]);
        $universal->taxes()->attach($iva->id, ['company_id' => $company->id]);
        $polish = Item::query()->create(['company_id' => $company->id, 'category_id' => $washes->id, 'internal_code' => 'BRI-001', 'type' => 'PRODUCT', 'name' => 'Brillo', 'unit' => 'Caneca', 'sale_price' => '40', 'is_active' => true, 'content_quantity' => '20', 'content_unit' => 'L']);
        Item::query()->create(['company_id' => $company->id, 'category_id' => $drinks->id, 'internal_code' => 'AGU-001', 'type' => 'PRODUCT', 'name' => 'Agua', 'unit' => 'UND', 'sale_price' => '1', 'is_active' => true]);

        // Nothing shows until a category is turned on in the Lavadora; then its services and products do.
        $this->actingAs($owner)->getJson(self::API.'/services')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(self::API.'/categories', ['categoryPublicIds' => [$washes->public_id]])->assertOk()
            ->assertJsonPath('data.1.name', 'Lavadas Automotrices')->assertJsonPath('data.1.enabled', true)
            ->assertJsonPath('data.1.servicesCount', 1)->assertJsonPath('data.1.productsCount', 1)->assertJsonPath('data.0.enabled', false);
        $rates = $this->getJson(self::API.'/services')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['Brillo', 'Lavada Universal'], array_column($rates, 'name'));
        $this->assertSame(['PRODUCT', 'SERVICE'], array_column($rates, 'type'));
        [$product, $service] = [$rates[0]['publicId'], $rates[1]['publicId']];
        $this->patchJson(self::API."/services/{$service}", ['durationMinutes' => 45])->assertOk()->assertJsonPath('data.durationMinutes', 45);

        // A price per vehicle: its own codes, cost and price; the IVA is the base service's, never asked.
        $price = $this->postJson(self::API."/services/{$service}/prices", [
            'vehicleSpecPublicId' => $setup['spec']->public_id, 'auxiliaryCode' => 'AUX-D', 'costPrice' => '12', 'salePrice' => '20',
            'description' => 'Incluye aspirado', 'supplies' => [['itemPublicId' => $polish->public_id, 'quantity' => '500', 'measure' => 'ml']],
        ])->assertCreated()
            ->assertJsonPath('data.price', '23.00')->assertJsonPath('data.costPrice', '12.000000')->assertJsonPath('data.item.auxiliaryCode', 'AUX-D')
            ->assertJsonPath('data.effectiveDurationMinutes', 45)->assertJsonCount(1, 'data.supplies')->json('data');
        $this->assertNotNull($price['item']['barcode']);
        $item = Item::query()->where('public_id', $price['item']['publicId'])->firstOrFail();
        $this->assertSame(['carwash', 'SERVICE'], [$item->managed_by, $item->type->value]);
        $this->assertStringStartsWith('Lavada Universal · Chevrolet', $item->name);
        $this->assertSame([$iva->id], $item->taxes()->pluck('company_taxes.id')->all());
        $this->assertSame($washes->id, $item->category_id);
        $this->postJson(self::API."/services/{$service}/prices", ['vehicleSpecPublicId' => $setup['spec']->public_id, 'salePrice' => '6'])
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_SERVICE_PRICE_DUPLICATE');
        $this->postJson(self::API."/services/{$product}/prices", ['vehicleSpecPublicId' => $setup['spec']->public_id, 'salePrice' => '6'])->assertUnprocessable();
        $this->patchJson(self::API."/service-prices/{$price['publicId']}", ['salePrice' => '10', 'isActive' => false])->assertOk()
            ->assertJsonPath('data.price', '11.50')->assertJsonPath('data.isActive', false);
        $this->assertFalse($item->refresh()->is_active);

        // The product says how much each price per vehicle uses of it: the same supplies, seen from the product.
        $this->putJson(self::API."/services/{$product}/usages", ['usages' => [['servicePricePublicId' => $price['publicId'], 'quantity' => '250', 'measure' => 'ml']]])
            ->assertOk()->assertJsonCount(1, 'data.usages')->assertJsonPath('data.usages.0.quantity', '250.000000')->assertJsonPath('data.usages.0.serviceName', 'Lavada Universal');

        // In Inventario the base service stays on sale with its own price, but it is not edited or deleted while it
        // has prices; a product used as a supply is not deleted.
        $this->patchJson("/api/v1/items/{$universal->public_id}", ['name' => 'Otra'])->assertConflict()->assertJsonPath('error.code', 'CARWASH_IN_USE');
        $this->putJson("/api/v1/items/{$universal->public_id}/taxes", ['taxPublicIds' => []])->assertConflict();
        $this->deleteJson("/api/v1/items/{$universal->public_id}")->assertConflict();
        $this->deleteJson("/api/v1/items/{$polish->public_id}")->assertConflict();
        $this->assertSame('Lavada Universal', $universal->refresh()->name);
        $this->assertSame(1, $universal->taxes()->count());

        // Taking the category off only hides it in Tarifas: Servicios still offers it.
        $this->putJson(self::API.'/categories', ['categoryPublicIds' => []])->assertOk();
        $this->getJson(self::API.'/services')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson(self::API."/service-prices/{$price['publicId']}", ['isActive' => true])->assertOk();
        $this->assertContains('Lavada Universal', array_column($this->getJson(self::API.'/services?for=orders')->json('data'), 'name'));
    }

    public function test_finishing_the_wash_uses_up_the_supplies_in_the_kardex(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $warehouse = Warehouse::query()->firstOrCreate(['establishment_id' => $company->establishments()->firstOrFail()->id, 'name' => 'Bodega Matriz'], ['company_id' => $company->id]);
        $polish = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'BRI-001', 'type' => 'PRODUCT', 'name' => 'Brillo', 'unit' => 'Caneca', 'sale_price' => '40', 'is_active' => true, 'content_quantity' => '20', 'content_unit' => 'L']);
        ItemStock::query()->create(['company_id' => $company->id, 'item_id' => $polish->id, 'warehouse_id' => $warehouse->id, 'quantity' => '3']);
        $setup['price']->supplies()->create(['company_id' => $company->id, 'item_id' => $polish->id, 'quantity' => '500', 'measure' => 'ml']);

        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');
        foreach (['ARRIVED', 'IN_PROGRESS'] as $status) {
            $this->postJson(self::API."/work-orders/{$order}/transitions", ['status' => $status])->assertOk();
        }
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson(self::API."/work-orders/{$order}/transitions", ['status' => 'READY'])->assertOk();

        $this->assertSame('2.975000', (string) ItemStock::query()->where('item_id', $polish->id)->value('quantity'));
        $movement = StockMovement::query()->where('item_id', $polish->id)->firstOrFail();
        $this->assertSame(StockMovement::CONSUMPTION, $movement->type);
        $this->assertSame('-0.025000', (string) $movement->quantity);
        $this->assertSame(['carwash', $order], [$movement->source_module, $movement->source_reference]);
    }

    public function test_names_are_unique_per_company_ignoring_case(): void
    {
        [, $owner] = $this->carwashCompany();

        $this->actingAs($owner)->postJson(self::API.'/vehicle-types', ['name' => 'Camioneta'])->assertCreated();
        $this->actingAs($owner)->postJson(self::API.'/vehicle-types', ['name' => '  camioneta '])->assertConflict()->assertJsonPath('error.code', 'CARWASH_DUPLICATE_NAME');
        $brand = $this->actingAs($owner)->postJson(self::API.'/vehicle-brands', ['name' => 'Toyota'])->assertCreated()->json('data.publicId');
    }

    public function test_a_specification_reuses_brand_and_type_by_name_and_sets_the_vehicle_type(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $operator = $this->member($company, 'BILLER');

        $spec = $this->actingAs($operator)->postJson(self::API.'/vehicle-specs', ['brand' => 'Toyota', 'model' => 'Corolla', 'type' => 'SUV'])->assertCreated()
            ->assertJsonPath('data.brand.name', 'Toyota')->assertJsonPath('data.type.name', 'SUV')->json('data');
        $this->postJson(self::API.'/vehicle-specs', ['brand' => ' toyota ', 'model' => 'Hilux', 'type' => 'suv'])->assertCreated()
            ->assertJsonPath('data.brand.publicId', $spec['brand']['publicId'])->assertJsonPath('data.type.publicId', $spec['type']['publicId']);
        $this->postJson(self::API.'/vehicle-specs', ['brand' => 'TOYOTA', 'model' => 'corolla', 'type' => 'SUV'])->assertConflict()->assertJsonPath('error.code', 'CARWASH_DUPLICATE_NAME');

        $vehicle = $this->postJson(self::API.'/vehicles', [
            'plate' => 'PCD1234', 'customerPublicId' => $setup['customer']->public_id, 'vehicleSpecPublicId' => $spec['publicId'],
        ])->assertCreated()->assertJsonPath('data.type.name', 'SUV')->assertJsonPath('data.brand.name', 'Toyota')->assertJsonPath('data.model.name', 'Corolla')->json('data.publicId');

        // Ordered by brand: the setup's Chevrolet Spark first, then Toyota Corolla and Hilux.
        $this->getJson(self::API.'/vehicle-specs')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.model', 'Corolla')->assertJsonPath('data.1.vehiclesCount', 1);
        // Turning it off is for managers; the vehicle keeps it.
        $this->patchJson(self::API."/vehicle-specs/{$spec['publicId']}", ['isActive' => false])->assertForbidden();
        $this->actingAs($owner)->patchJson(self::API."/vehicle-specs/{$spec['publicId']}", ['isActive' => false])->assertOk()->assertJsonPath('data.isActive', false);
        $this->getJson(self::API.'/vehicle-specs')->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson(self::API."/vehicles/{$vehicle}", ['vehicleSpecPublicId' => $spec['publicId'], 'color' => 'Gris'])->assertOk()->assertJsonPath('data.color', 'Gris');

        [$other, $otherOwner] = $this->carwashCompany('1799999998001', ready: false);
        $this->actingAs($otherOwner)->getJson(self::API.'/vehicle-specs')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson(self::API."/vehicle-specs/{$spec['publicId']}", ['isActive' => true])->assertNotFound();
    }

    public function test_vehicles_filter_by_brand_type_and_state_and_report_company_totals(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $spec = $this->actingAs($owner)->postJson(self::API.'/vehicle-specs', ['brand' => 'Kia', 'model' => 'Picanto', 'type' => 'Auto pequeño'])->json('data');
        $this->postJson(self::API.'/vehicles', ['plate' => 'PKA1111', 'customerPublicId' => $setup['customer']->public_id, 'vehicleSpecPublicId' => $spec['publicId']])->assertCreated();
        $setup['vehicle']->update(['is_active' => false]);

        $this->getJson(self::API.'/vehicles?includeInactive=1')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary', ['vehicles' => 2, 'active' => 1, 'brands' => 2, 'customersWithVehicle' => 1]);
        $this->getJson(self::API.'/vehicles?brandPublicId='.$spec['brand']['publicId'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plate', 'PKA1111');
        $this->getJson(self::API.'/vehicles?typePublicId='.$setup['type']->public_id.'&status=INACTIVE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plate', 'PBA1234');
        $this->getJson(self::API.'/vehicles')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_operator_creates_a_brand_and_a_manager_edits_a_specification_and_its_vehicles_follow(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $operator = $this->member($company, 'BILLER');

        // The «+» next to Marca in Servicios: operators may add a brand on the spot, not rename it.
        $brand = $this->actingAs($operator)->postJson(self::API.'/vehicle-brands', ['name' => 'Mazda'])->assertCreated()->json('data');
        $this->patchJson(self::API."/vehicle-brands/{$brand['publicId']}", ['name' => 'Mazda Motors'])->assertForbidden();

        $spec = $this->postJson(self::API.'/vehicle-specs', ['brand' => 'Mazda', 'model' => 'CX5', 'type' => 'Sedán'])->assertCreated()->json('data');
        $this->postJson(self::API.'/vehicle-specs', ['brand' => 'Mazda', 'model' => 'CX-5', 'type' => 'SUV'])->assertCreated();
        $vehicle = $this->postJson(self::API.'/vehicles', [
            'plate' => 'PMZ5555', 'customerPublicId' => $setup['customer']->public_id, 'vehicleSpecPublicId' => $spec['publicId'],
        ])->assertCreated()->assertJsonPath('data.type.name', 'Sedán')->json('data.publicId');

        $this->patchJson(self::API."/vehicle-specs/{$spec['publicId']}", ['model' => 'CX-5'])->assertForbidden();
        $this->actingAs($owner)->patchJson(self::API."/vehicle-specs/{$spec['publicId']}", ['model' => 'cx-5', 'type' => ' suv '])
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_DUPLICATE_NAME');
        $this->patchJson(self::API."/vehicle-specs/{$spec['publicId']}", ['model' => ' CX-5  Grand ', 'type' => 'SUV'])->assertOk()
            ->assertJsonPath('data.model', 'CX-5 Grand')->assertJsonPath('data.type.name', 'SUV')->assertJsonPath('data.isActive', true);
        // The type sets the price: the vehicle takes the new one.
        $this->getJson(self::API."/vehicles/{$vehicle}")->assertOk()->assertJsonPath('data.type.name', 'SUV')->assertJsonPath('data.model.name', 'CX-5 Grand');
    }
}
