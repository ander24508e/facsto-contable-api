<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\Vehicle;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Turns the chosen services into order lines using the price for the vehicle's specification (brand + model +
 * type): each line points to the FACSTO item that will be invoiced. A service without an active price (or with an
 * inactive item) for that specification cannot be ordered.
 */
final class WorkOrderLineResolver
{
    /**
     * @param  list<string>  $servicePublicIds
     * @return list<array<string, mixed>>
     */
    public function resolve(Vehicle $vehicle, array $servicePublicIds): array
    {
        $lines = [];
        foreach (array_values(array_unique($servicePublicIds)) as $index => $publicId) {
            $service = Service::query()->where('company_id', $vehicle->company_id)->where('public_id', $publicId)->where('is_active', true)->first();
            $price = $service && $vehicle->vehicle_model_id ? ServicePrice::query()
                ->with('item')
                ->where('service_id', $service->id)
                ->where('vehicle_model_id', $vehicle->vehicle_model_id)
                ->where('is_active', true)
                ->first() : null;
            if (! $service || ! $price || ! $price->item?->is_active) {
                throw new ApiException(ApiErrorCode::CARWASH_SERVICE_PRICE_MISSING, 'Uno de los servicios no tiene precio activo para este vehículo.', 422, [
                    'errors' => ["servicePublicIds.$index" => ['El servicio no tiene precio activo para la marca y el modelo de este vehículo.']],
                ]);
            }
            $price->setRelation('service', $service);
            $lines[] = [
                'company_id' => $vehicle->company_id,
                'service_id' => $service->id,
                'service_price_id' => $price->id,
                'item_id' => $price->item_id,
                'description' => $service->name,
                'quantity' => '1',
                'duration_minutes' => $price->effectiveDuration(),
            ];
        }

        return $lines;
    }
}
