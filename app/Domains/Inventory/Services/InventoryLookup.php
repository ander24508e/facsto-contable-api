<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Models\Warehouse;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class InventoryLookup
{
    public function activeWarehouse(Company $company, string $publicId): Warehouse
    {
        $warehouse = Warehouse::query()->where('company_id', $company->id)->where('public_id', $publicId)->where('is_active', true)->first();
        if (! $warehouse) {
            throw new ApiException(ApiErrorCode::WAREHOUSE_NOT_FOUND, 'La bodega no está activa en la empresa actual.', 422);
        }

        return $warehouse;
    }
}
