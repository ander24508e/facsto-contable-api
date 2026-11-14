<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\WorkOrderLine;
use App\Domains\Catalog\Models\Item;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns the chosen services into order lines; each line points to the FACSTO item that will be invoiced.
 * - Configuración › Tarifas «Precio por vehículo» (default): the price for the vehicle's specification (brand + model +
 *   type); a service without an active price for it cannot be ordered.
 * - «Precio único»: the service's own Inventario item and duration, the same for every vehicle.
 * Surcharges (› Tarifas) are Inventario services added as their own lines: «Suciedad extrema» when the order says so and
 * «Hora pico» when the order's time falls inside it.
 */
final class WorkOrderLineResolver
{
    /**
     * @param  list<string>  $servicePublicIds
     * @return list<array<string, mixed>>
     */
    public function resolve(Vehicle $vehicle, array $servicePublicIds): array
    {
        $single = CarwashSetting::forCompany($vehicle->company_id)->pricing_mode === CarwashSetting::PRICING_SINGLE;
        $lines = [];
        foreach (array_values(array_unique($servicePublicIds)) as $index => $publicId) {
            $service = Service::query()->with('item')->where('company_id', $vehicle->company_id)->where('public_id', $publicId)
                ->whereHas('item', fn ($item) => $item->where('is_active', true))->first();
            if ($single && $service) {
                $lines[] = $this->line($vehicle->company_id, $service->id, null, $service->item_id, $service->item->name, $service->duration_minutes);

                continue;
            }
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
            $lines[] = $this->line($vehicle->company_id, $service->id, $price->id, $price->item_id, $service->item->name, $price->effectiveDuration());
        }

        return $lines;
    }

    /**
     * The surcharge lines of an order: «Suciedad extrema» when `$dirty`, «Hora pico» when `$at` (the reservation, or
     * now for a walk-in) is inside the peak hours. Inactive surcharge items are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public function surcharges(int $companyId, bool $dirty, ?CarbonInterface $at): array
    {
        $settings = CarwashSetting::forCompany($companyId);
        $lines = [];
        if ($dirty && $settings->dirty_surcharge_item_id) {
            $lines[] = $this->surchargeLine($companyId, $settings->dirty_surcharge_item_id);
        }
        if ($settings->peak_surcharge_item_id && $settings->peak_from !== null && $settings->peak_to !== null) {
            $time = CarbonImmutable::instance($at ?? now())->setTimezone(config('app.timezone'))->format('H:i');
            if ($time >= substr((string) $settings->peak_from, 0, 5) && $time < substr((string) $settings->peak_to, 0, 5)) {
                $lines[] = $this->surchargeLine($companyId, $settings->peak_surcharge_item_id);
            }
        }

        return array_values(array_filter($lines));
    }

    /** @return array<string, mixed>|null */
    private function surchargeLine(int $companyId, int $itemId): ?array
    {
        $item = Item::query()->where('company_id', $companyId)->whereKey($itemId)->where('is_active', true)->first();

        return $item ? [...$this->line($companyId, null, null, $item->id, $item->name, 0), 'kind' => WorkOrderLine::SURCHARGE] : null;
    }

    /** @return array<string, mixed> */
    private function line(int $companyId, ?int $serviceId, ?int $priceId, int $itemId, string $description, int $minutes): array
    {
        return [
            'company_id' => $companyId,
            'kind' => WorkOrderLine::SERVICE,
            'service_id' => $serviceId,
            'service_price_id' => $priceId,
            'item_id' => $itemId,
            'description' => $description,
            'quantity' => '1',
            'duration_minutes' => $minutes,
        ];
    }
}
