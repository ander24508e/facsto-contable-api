<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\ServiceSupply;
use App\Domains\Catalog\Models\Item;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;

/**
 * Keeps Tarifas and the invoice in step without touching Inventario's code: the Lavadora listens to the item's own
 * model events. A service with prices per vehicle is not edited or deleted from Inventario (only its photo), since
 * its prices copy its name, category and IVA; a product used as a supply is not deleted.
 */
final class CatalogGuard
{
    /** Columns that may still change on a service with prices (its photo). */
    private const FREE = ['image_path', 'updated_at'];

    public static function register(): void
    {
        Item::updating(function (Item $item): void {
            if (array_diff(array_keys($item->getDirty()), self::FREE) !== [] && self::hasPrices($item->id)) {
                throw self::locked('editar');
            }
        });
        Item::deleting(function (Item $item): void {
            if (self::hasPrices($item->id)) {
                throw self::locked('eliminar');
            }
            if (ServiceSupply::query()->where('item_id', $item->id)->exists()) {
                throw new ApiException(ApiErrorCode::CARWASH_IN_USE, 'Este producto es insumo de precios por vehículo de la Lavadora; quítalo de ahí antes de eliminarlo.', 409);
            }
        });
        // The IVA of an item changes through its pivot, which has no model events: guard that request itself.
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if ($event->request->isMethod('PUT') && str_ends_with((string) $event->route->uri(), 'items/{item}/taxes')) {
                $item = Item::query()->where('public_id', (string) $event->route->parameter('item'))->value('id');
                if ($item && self::hasPrices($item)) {
                    throw self::locked('editar');
                }
            }
        });
    }

    private static function hasPrices(int $itemId): bool
    {
        return ServicePrice::query()->whereHas('service', fn ($service) => $service->where('item_id', $itemId))->exists();
    }

    private static function locked(string $action): ApiException
    {
        return new ApiException(ApiErrorCode::CARWASH_IN_USE, "No se puede {$action}: este servicio tiene precios por vehículo en la Lavadora.", 409);
    }
}
