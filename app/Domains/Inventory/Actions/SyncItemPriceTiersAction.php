<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Catalog\Models\Item;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/** Replaces the wholesale prices of an item; two prices for the same quantity are rejected. */
final class SyncItemPriceTiersAction
{
    /** @param list<array{minQuantity: string, price: string}> $tiers */
    public function execute(Item $item, array $tiers): void
    {
        $quantities = array_map(fn (array $tier): string => bcadd($tier['minQuantity'], '0', 6), $tiers);
        if (count($quantities) !== count(array_unique($quantities))) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Hay dos precios alternos para la misma cantidad.', 422);
        }

        $item->priceTiers()->delete();
        foreach ($tiers as $tier) {
            $item->priceTiers()->create(['company_id' => $item->company_id, 'min_quantity' => $tier['minQuantity'], 'price' => $tier['price']]);
        }
    }
}
