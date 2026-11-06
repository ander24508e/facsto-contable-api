<?php

namespace Tests\Feature\Carwash;

use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Companies\Enums\CompanyRole;

/** Vehicles of FACSTO customers, prices per vehicle that are Inventario items, and their supplies. */
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

    public function test_a_price_per_vehicle_creates_its_inventory_item_with_the_service_iva_and_its_supplies(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $iva = $this->companyIva($company, '4');
        $service = $this->actingAs($owner)->postJson(self::API.'/services', [
            'name' => 'Lavada Express', 'taxPublicId' => $iva->public_id, 'durationMinutes' => 40, 'isFeatured' => true,
        ])->assertCreated()->assertJsonPath('data.tax.publicId', $iva->public_id)->assertJsonPath('data.isBookable', true)->json('data.publicId');
        $polish = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'BRI-001', 'type' => 'PRODUCT', 'name' => 'Brillo', 'unit' => 'Caneca', 'sale_price' => '40', 'is_active' => true, 'content_quantity' => '20', 'content_unit' => 'L']);
        $cloth = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'PAN-001', 'type' => 'PRODUCT', 'name' => 'Paño', 'unit' => 'UND', 'sale_price' => '2', 'is_active' => true]);

        $price = $this->postJson(self::API."/services/{$service}/prices", [
            'vehicleSpecPublicId' => $setup['spec']->public_id, 'salePrice' => '5.75', 'priceIncludesTax' => true, 'durationMinutes' => 45,
            'description' => 'Incluye aspirado', 'supplies' => [
                ['itemPublicId' => $polish->public_id, 'quantity' => '500', 'measure' => 'ml'],
                ['itemPublicId' => $cloth->public_id, 'quantity' => '1'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.spec.model', 'Spark')->assertJsonPath('data.vehicleType.publicId', $setup['type']->public_id)
            ->assertJsonPath('data.price', '5.75')->assertJsonPath('data.effectiveDurationMinutes', 45)->assertJsonCount(2, 'data.supplies')
            ->json('data');
        $item = Item::query()->where('public_id', $price['item']['publicId'])->firstOrFail();
        $this->assertSame('carwash', $item->managed_by);
        $this->assertSame('SERVICE', $item->type->value);
        $this->assertStringStartsWith('Lavada Express · Chevrolet', $item->name);
        $this->assertSame([$iva->id], $item->taxes()->pluck('company_taxes.id')->all());

        // One price per specification; a measure needs the product's content per unit.
        $this->postJson(self::API."/services/{$service}/prices", ['vehicleSpecPublicId' => $setup['spec']->public_id, 'salePrice' => '6'])
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_SERVICE_PRICE_DUPLICATE');
        $this->patchJson(self::API."/service-prices/{$price['publicId']}", ['supplies' => [['itemPublicId' => $cloth->public_id, 'quantity' => '1', 'measure' => 'ml']]])
            ->assertUnprocessable();

        // The price is the item's; renaming the service or turning the price off reaches the item.
        $this->patchJson(self::API."/service-prices/{$price['publicId']}", ['salePrice' => '10', 'priceIncludesTax' => false, 'isActive' => false])->assertOk()
            ->assertJsonPath('data.price', '11.50')->assertJsonPath('data.isActive', false)->assertJsonCount(2, 'data.supplies');
        $this->patchJson(self::API."/services/{$service}", ['name' => 'Lavada Premium'])->assertOk()->assertJsonPath('data.pricesCount', 1);
        $item->refresh();
        $this->assertStringStartsWith('Lavada Premium · Chevrolet', $item->name);
        $this->assertFalse($item->is_active);
        $this->getJson("/api/v1/items/{$item->public_id}")->assertOk()->assertJsonPath('data.managedBy', 'carwash');
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
        $this->actingAs($owner)->postJson(self::API.'/washers', ['name' => 'Luis', 'phone' => '0991234567'])->assertCreated()->assertJsonPath('data.phone', '0991234567');
    }

    public function test_a_specification_reuses_brand_and_type_by_name_and_sets_the_vehicle_type(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $operator = $this->member($company, CompanyRole::BILLER);

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
        $operator = $this->member($company, CompanyRole::BILLER);

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
