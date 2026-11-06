<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Carwash\Services\ServicePriceItems;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\ItemContent;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * A price per vehicle: the service for one specification (brand + model + type), with the price typed by the
 * business, its duration, a description and the supplies each wash uses. One price per service × specification.
 * The price lives in its Inventario item (ServicePriceItems), so Ventas invoices it like any other item.
 */
final class SaveServicePriceAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ServicePriceItems $items,
        private readonly ItemContent $content,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data vehicleSpecPublicId, salePrice, priceIncludesTax, durationMinutes, description, isActive, supplies */
    public function create(Service $service, array $data, User $actor): ServicePrice
    {
        $company = $this->context->company();
        if (! $service->company_tax_id) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Elige la tarifa de IVA del servicio antes de ponerle precios.', 422);
        }
        $spec = VehicleModel::query()->where('company_id', $company->id)->where('public_id', $data['vehicleSpecPublicId'])->where('is_active', true)->first()
            ?? throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La especificación no existe o está inactiva.', 422, ['errors' => ['vehicleSpecPublicId' => ['La especificación no existe o está inactiva.']]]);
        if (ServicePrice::query()->where('service_id', $service->id)->where('vehicle_model_id', $spec->id)->exists()) {
            throw new ApiException(ApiErrorCode::CARWASH_SERVICE_PRICE_DUPLICATE, 'Este servicio ya tiene precio para esa especificación.', 409, ['errors' => ['vehicleSpecPublicId' => ['Este servicio ya tiene precio para esa especificación.']]]);
        }
        $supplies = array_key_exists('supplies', $data) ? $this->supplies($data['supplies'] ?? [], []) : [];

        return DB::transaction(function () use ($service, $spec, $data, $supplies, $actor, $company): ServicePrice {
            $active = (bool) ($data['isActive'] ?? true);
            $item = $this->items->create($service, $spec, [
                'salePrice' => (string) $data['salePrice'],
                'priceIncludesTax' => (bool) ($data['priceIncludesTax'] ?? false),
                'isActive' => $active,
            ], $actor);
            $price = ServicePrice::query()->create([
                'company_id' => $company->id, 'service_id' => $service->id, 'vehicle_model_id' => $spec->id, 'item_id' => $item->id,
                'duration_minutes' => $data['durationMinutes'] ?? null, 'description' => $this->text($data['description'] ?? null), 'is_active' => $active,
            ]);
            $this->writeSupplies($price, $supplies);
            $this->audit->record($actor, $company, 'carwash.service_price.created', $price, null, ['service' => $service->public_id, 'spec' => $spec->public_id, 'item' => $item->public_id, 'supplies' => count($supplies)]);

            return $price;
        });
    }

    /** @param array<string, mixed> $data salePrice, priceIncludesTax, durationMinutes, description, isActive, supplies */
    public function update(ServicePrice $price, array $data, User $actor): ServicePrice
    {
        $company = $this->context->company();
        $current = $price->supplies()->pluck('item_id')->all();
        $supplies = array_key_exists('supplies', $data) ? $this->supplies($data['supplies'] ?? [], $current) : null;
        $attributes = [];
        if (array_key_exists('durationMinutes', $data)) {
            $attributes['duration_minutes'] = $data['durationMinutes'];
        }
        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->text($data['description']);
        }
        if (array_key_exists('isActive', $data)) {
            $attributes['is_active'] = (bool) $data['isActive'];
        }
        $itemChanges = array_intersect_key($data, array_flip(['salePrice', 'priceIncludesTax']));

        return DB::transaction(function () use ($price, $attributes, $itemChanges, $supplies, $actor, $company): ServicePrice {
            $before = $price->only(array_keys($attributes));
            $price->fill($attributes)->save();
            $this->items->update($price, $itemChanges, $actor);
            if ($supplies !== null) {
                $this->writeSupplies($price, $supplies);
            }
            $this->audit->record($actor, $company, 'carwash.service_price.updated', $price, $before, [...$price->only(array_keys($attributes)), ...$itemChanges, 'supplies' => $supplies === null ? null : count($supplies)]);

            return $price;
        });
    }

    /**
     * Products of Inventario (the same that are sold), each once, with a quantity above zero. A measure needs the
     * product's content per unit in the same family (ml/L, g/kg). A product that was turned off may stay if it was
     * already a supply of this price.
     *
     * @param  list<array{itemPublicId: string, quantity: string, measure?: string|null}>  $input
     * @param  list<int>  $current
     * @return list<array{item_id: int, quantity: string, measure: ?string}>
     */
    private function supplies(array $input, array $current): array
    {
        $company = $this->context->company();
        $rows = [];
        foreach (array_values($input) as $index => $supply) {
            $item = $company->items()->where('public_id', $supply['itemPublicId'])->first();
            if (! $item || $item->type !== ItemType::PRODUCT || (! $item->is_active && ! in_array($item->id, $current, true))) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Los insumos son productos activos de Inventario.', 422, ['errors' => ["supplies.$index.itemPublicId" => ['Elige un producto activo de Inventario.']]]);
            }
            $measure = $supply['measure'] ?? null;
            if (! $this->content->accepts($item, $measure)) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, "«{$item->name}» no tiene contenido por unidad en esa medida.", 422, ['errors' => ["supplies.$index.measure" => ['Pon el contenido por unidad del producto en Inventario o usa su propia unidad.']]]);
            }
            $rows[$item->id] = ['item_id' => $item->id, 'quantity' => bcadd((string) $supply['quantity'], '0', 6), 'measure' => $measure];
        }

        return array_values($rows);
    }

    /** @param list<array{item_id: int, quantity: string, measure: ?string}> $supplies */
    private function writeSupplies(ServicePrice $price, array $supplies): void
    {
        $price->supplies()->delete();
        foreach ($supplies as $supply) {
            $price->supplies()->create([...$supply, 'company_id' => $price->company_id]);
        }
    }

    private function text(mixed $value): ?string
    {
        return trim((string) $value) ?: null;
    }
}
