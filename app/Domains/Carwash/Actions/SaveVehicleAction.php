<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Carwash\Models\VehicleType;
use App\Domains\Carwash\Services\PlateNormalizer;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Registers or edits a vehicle of a FACSTO customer. The plate is unique per company. */
final class SaveVehicleAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PlateNormalizer $plates,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  plate, customerPublicId, vehicleSpecPublicId (brand + model + type) or only
     *                                      vehicleTypePublicId (type only), color, year, notes, isActive
     */
    public function execute(Vehicle $vehicle, array $data, User $actor): Vehicle
    {
        $company = $this->context->company();
        $attributes = [];

        if (array_key_exists('plate', $data)) {
            $attributes['plate'] = $this->plates->normalize((string) $data['plate']);
            $taken = Vehicle::query()->where('company_id', $company->id)->where('plate', $attributes['plate'])
                ->when($vehicle->exists, fn ($query) => $query->whereKeyNot($vehicle->id))->exists();
            if ($taken) {
                throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_PLATE_DUPLICATE, 'Ya existe un vehículo con esa placa.', 409, ['errors' => ['plate' => ['Ya existe un vehículo con esa placa.']]]);
            }
        }
        if (array_key_exists('customerPublicId', $data)) {
            $attributes['customer_id'] = $company->customers()->where('public_id', $data['customerPublicId'])->where('is_active', true)->firstOrFail()->id;
        }
        if (filled($data['vehicleTypePublicId'] ?? null)) {
            $attributes['vehicle_type_id'] = VehicleType::query()->where('company_id', $company->id)
                ->where('public_id', $data['vehicleTypePublicId'])->where('is_active', true)->firstOrFail()->id;
        }
        // The specification decides brand, model and type (the type sets the price). The one the vehicle
        // already has may stay even if it was deactivated later; a new one must be active.
        if (array_key_exists('vehicleSpecPublicId', $data)) {
            if ($data['vehicleSpecPublicId'] === null) {
                $attributes['vehicle_brand_id'] = null;
                $attributes['vehicle_model_id'] = null;
            } else {
                $spec = VehicleModel::query()->where('company_id', $company->id)->where('public_id', $data['vehicleSpecPublicId'])
                    ->where(fn ($usable) => $usable->where('is_active', true)->when($vehicle->vehicle_model_id, fn ($current) => $current->orWhere('id', $vehicle->vehicle_model_id)))
                    ->firstOrFail();
                $attributes['vehicle_brand_id'] = $spec->vehicle_brand_id;
                $attributes['vehicle_model_id'] = $spec->id;
                $attributes['vehicle_type_id'] = $spec->vehicle_type_id;
            }
        }
        foreach (['color' => 'color', 'year' => 'year', 'notes' => 'notes', 'isActive' => 'is_active'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = is_string($data[$input]) ? (trim($data[$input]) ?: null) : $data[$input];
            }
        }

        $this->assertRequired($company->id, $vehicle, $attributes);

        return DB::transaction(function () use ($vehicle, $attributes, $actor, $company): Vehicle {
            $before = $vehicle->exists ? $vehicle->only(array_keys($attributes)) : null;
            $isNew = ! $vehicle->exists;
            $vehicle->fill([...$attributes, 'company_id' => $company->id])->save();
            $this->audit->record($actor, $company, $isNew ? 'carwash.vehicle.created' : 'carwash.vehicle.updated', $vehicle, $before, $vehicle->only(array_keys($attributes)));

            return $vehicle->load(['customer', 'type', 'brand', 'model']);
        });
    }

    /**
     * Configuración › Vehículos: the data the company requires (color, brand and model, year) and, when it says so, an
     * Ecuadorian plate (cars ABC1234 or ABC123, motorcycles AB123C).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertRequired(int $companyId, Vehicle $vehicle, array $attributes): void
    {
        $settings = CarwashSetting::forCompany($companyId);
        $value = fn (string $column) => array_key_exists($column, $attributes) ? $attributes[$column] : $vehicle->{$column};
        $errors = [];
        if (isset($attributes['plate']) && $settings->validate_ec_plate && ! preg_match('/^([A-Z]{3}\d{3,4}|[A-Z]{2}\d{3}[A-Z])$/', $attributes['plate'])) {
            $errors['plate'] = ['La placa no tiene el formato de Ecuador (ABC1234 o AB123C para motos).'];
        }
        if ($settings->require_color && blank($value('color'))) {
            $errors['color'] = ['El color es obligatorio.'];
        }
        if ($settings->require_brand_model && blank($value('vehicle_model_id'))) {
            $errors['vehicleSpecPublicId'] = ['La marca y el modelo son obligatorios.'];
        }
        if ($settings->require_year && blank($value('year'))) {
            $errors['year'] = ['El año es obligatorio.'];
        }
        if ($errors !== []) {
            throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_DATA_REQUIRED, collect($errors)->flatten()->first(), 422, ['errors' => $errors]);
        }
    }
}
