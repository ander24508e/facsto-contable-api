<?php

namespace Tests\Feature\Carwash;

use App\Domains\Carwash\Models\AreaType;
use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\VehicleBrand;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Carwash\Models\VehicleType;
use App\Domains\Carwash\Models\WorkArea;
use App\Domains\Carwash\Services\AreaTypeCatalog;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** A company able to invoice, with the Lavadora contracted and a priced service ready to order. */
abstract class CarwashTestCase extends InvoiceFeatureTestCase
{
    protected const API = '/api/v1/carwash';

    /** @return array{0: Company, 1: User} */
    protected function carwashCompany(string $ruc = '1799999999001', EntitlementStatus $status = EntitlementStatus::ENABLED, bool $ready = true): array
    {
        $owner = User::factory()->create();
        // Only one company per test can issue (the test certificate has one RUC); the others only operate.
        $company = $ready ? $this->readyCompany($owner, $ruc) : $this->createCompany($owner, $ruc);
        CompanyEntitlement::query()->create(['company_id' => $company->id, 'feature' => 'carwash', 'status' => $status]);

        return [$company, $owner];
    }

    protected function member(Company $company, string $preset): User
    {
        return $this->memberAs($company, $preset);
    }

    /**
     * Vehicle type and specification, the service (an Inventario item, IVA 15 %) priced for that specification with its FACSTO item
     * ($10.00 → $11.50), a customer's vehicle of that specification and a washer.
     *
     * @return array{type: VehicleType, spec: VehicleModel, item: Item, service: Service, price: ServicePrice, customer: Customer, vehicle: Vehicle, washer: User}
     */
    protected function carwashSetup(Company $company, string $plate = 'PBA1234'): array
    {
        $type = VehicleType::query()->create(['company_id' => $company->id, 'name' => 'Automóvil '.$plate]);
        $brand = VehicleBrand::query()->create(['company_id' => $company->id, 'name' => 'Chevrolet '.$plate]);
        $spec = VehicleModel::query()->create(['company_id' => $company->id, 'vehicle_brand_id' => $brand->id, 'vehicle_type_id' => $type->id, 'name' => 'Spark']);
        $iva = $this->companyIva($company, '4');
        $base = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'LAVB-'.$plate, 'type' => 'SERVICE', 'name' => 'Lavado completo '.$plate, 'unit' => 'UND', 'sale_price' => '9.000000', 'is_active' => true]);
        $base->taxes()->attach($iva->id, ['company_id' => $company->id]);
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'LAV-'.$plate, 'type' => 'SERVICE', 'name' => 'Lavado completo', 'unit' => 'UND', 'sale_price' => '10.000000', 'is_active' => true, 'managed_by' => 'carwash']);
        $item->taxes()->attach($iva->id, ['company_id' => $company->id]);
        $service = Service::query()->create(['company_id' => $company->id, 'item_id' => $base->id, 'duration_minutes' => 40]);
        $price = ServicePrice::query()->create(['company_id' => $company->id, 'service_id' => $service->id, 'vehicle_model_id' => $spec->id, 'item_id' => $item->id]);
        $customer = Customer::query()->firstOrCreate(
            ['company_id' => $company->id, 'identification_type' => IdentificationType::CEDULA, 'identification' => '1710034065'],
            ['legal_name' => 'María Pérez', 'is_active' => true],
        );
        $vehicle = Vehicle::query()->create(['company_id' => $company->id, 'customer_id' => $customer->id, 'plate' => $plate, 'vehicle_type_id' => $type->id, 'vehicle_model_id' => $spec->id, 'vehicle_brand_id' => $brand->id]);
        $washer = $this->memberAs($company, ['carwash.assignable', 'carwash.own_work']);
        $washer->forceFill(['name' => 'Carlos '.$plate])->save();

        return compact('type', 'spec', 'item', 'service', 'price', 'customer', 'vehicle', 'washer');
    }

    /** @return list<AreaType> Lavado, Secado and Lubricación of the company */
    protected function areaTypes(Company $company): array
    {
        app(AreaTypeCatalog::class)->ensureFixed($company->id);

        return AreaType::query()->where('company_id', $company->id)->whereNotNull('code')->ordered()->get()->all();
    }

    /** @param list<AreaType> $types */
    protected function workArea(Company $company, string $name, array $types, int $capacity, string $opens, string $closes): WorkArea
    {
        $area = WorkArea::query()->create(['company_id' => $company->id, 'name' => $name, 'capacity' => $capacity, 'opens_at' => $opens, 'closes_at' => $closes]);
        $area->types()->sync(collect($types)->mapWithKeys(fn ($type) => [$type->id => ['company_id' => $company->id]])->all());

        return $area;
    }

    /** @return array<string, mixed> */
    protected function orderBody(array $setup, array $extra = []): array
    {
        return ['vehiclePublicId' => $setup['vehicle']->public_id, 'servicePublicIds' => [$setup['service']->public_id], ...$extra];
    }
}
