<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Actions\SaveVehicleAction;
use App\Domains\Carwash\Models\Vehicle;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonInterface;

/**
 * The vehicle of an order as the counter sends it (Nueva agenda and its edit): by its id or by its plate (the same
 * plate is the same vehicle; a new one is registered with its specification), and its lines. Nueva agenda registers
 * the vehicle in Vehículos and its specification in Especificaciones before the order, so it sends the id. The
 * specification is required: it decides which services and prices apply.
 */
final class WorkOrderVehicles
{
    public function __construct(
        private readonly SaveVehicleAction $vehicles,
        private readonly PlateNormalizer $plates,
        private readonly WorkOrderLineResolver $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $data  vehiclePublicId or plate, vehicleSpecPublicId
     */
    public function vehicle(int $companyId, array $data, User $actor): Vehicle
    {
        if (filled($data['vehiclePublicId'] ?? null)) {
            $vehicle = Vehicle::query()->where('company_id', $companyId)->where('public_id', $data['vehiclePublicId'])->first()
                ?? throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_NOT_FOUND, 'El vehículo no existe.', 404);
            if (filled($data['vehicleSpecPublicId'] ?? null) && $data['vehicleSpecPublicId'] !== $vehicle->model?->public_id) {
                $vehicle = $this->vehicles->execute($vehicle, ['vehicleSpecPublicId' => $data['vehicleSpecPublicId']], $actor);
            }
        } else {
            $spec = filled($data['vehicleSpecPublicId'] ?? null) ? $data['vehicleSpecPublicId'] : null;
            $vehicle = Vehicle::query()->where('company_id', $companyId)->where('plate', $this->plates->normalize((string) ($data['plate'] ?? '')))->first();
            if (! $vehicle) {
                return $this->vehicles->execute(new Vehicle, ['plate' => $data['plate'] ?? '', 'vehicleSpecPublicId' => $spec ?? $this->withoutSpec(), 'isActive' => true], $actor);
            }
            if ($spec !== null && $spec !== $vehicle->model?->public_id) {
                $vehicle = $this->vehicles->execute($vehicle, ['vehicleSpecPublicId' => $spec], $actor);
            }
        }
        if (! $vehicle->is_active) {
            throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_INACTIVE, 'El vehículo está inactivo.', 422);
        }
        if ($vehicle->vehicle_model_id === null) {
            $this->withoutSpec();
        }

        return $vehicle;
    }

    private function withoutSpec(): never
    {
        throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Elige la especificación del vehículo.', 422, ['errors' => ['vehicleSpecPublicId' => ['Elige la especificación del vehículo.']]]);
    }

    /**
     * Its services (priced for the vehicle) and the surcharges of the order's time and dirt, without its products.
     *
     * @param  list<string>  $servicePublicIds
     * @return list<array<string, mixed>>
     */
    public function serviceLines(Vehicle $vehicle, array $servicePublicIds, bool $dirty, ?CarbonInterface $at): array
    {
        return [...$this->lines->resolve($vehicle, $servicePublicIds), ...$this->lines->surcharges($vehicle->company_id, $dirty, $at)];
    }

    /**
     * @param  list<array{itemPublicId: string, quantity: string}>  $items
     * @return list<array<string, mixed>>
     */
    public function productLines(int $companyId, array $items): array
    {
        return $this->lines->articles($companyId, $items);
    }
}
