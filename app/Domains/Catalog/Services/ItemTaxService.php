<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\Tax;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class ItemTaxService
{
    /** @param list<string> $taxPublicIds */
    public function sync(Item $item, array $taxPublicIds): void
    {
        $taxes = Tax::query()->whereIn('public_id', $taxPublicIds)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->get();

        if ($taxes->count() !== count(array_unique($taxPublicIds))) {
            throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'Una o más tasas no están vigentes para la fecha actual.', 422);
        }

        $item->taxes()->sync($taxes->modelKeys());
    }
}
