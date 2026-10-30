<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\ItemCatalogMetadata;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Actions\SetItemStockAction;
use App\Domains\Inventory\Actions\SyncItemPriceTiersAction;
use App\Domains\Inventory\Services\InventoryLookup;
use App\Domains\Taxes\Services\ItemTaxService;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CreateItemAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
        private readonly ItemTaxService $taxes,
        private readonly InventoryLookup $inventory,
        private readonly SetItemStockAction $stock,
        private readonly SyncItemPriceTiersAction $priceTiers,
    ) {}

    public function execute(array $attributes, User $actor): Item
    {
        $company = $this->context->company();
        $categoryPublicId = $attributes['category_public_id'] ?? null;
        $brandPublicId = $attributes['brand_public_id'] ?? null;
        $barcode = trim((string) ($attributes['barcode'] ?? '')) ?: null;
        $stock = $attributes['stock'] ?? null;
        $tiers = $attributes['price_tiers'] ?? [];
        unset($attributes['category_public_id'], $attributes['brand_public_id'], $attributes['barcode'], $attributes['stock'], $attributes['price_tiers']);
        if ($categoryPublicId !== null) {
            $category = $company->categories()->where('public_id', $categoryPublicId)->where('is_active', true)->first();
            if (! $category) {
                throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no está activa en la empresa actual.', 422);
            }
            $attributes['category_id'] = $category->id;
        }
        $brand = null;
        if ($brandPublicId !== null) {
            $brand = $company->brands()->where('public_id', $brandPublicId)->where('is_active', true)->first();
            if (! $brand) {
                throw new ApiException(ApiErrorCode::BRAND_NOT_FOUND, 'La marca no está activa en la empresa actual.', 422);
            }
        }
        if ($barcode !== null && ItemCatalogMetadata::query()->where('company_id', $company->id)->where('barcode', $barcode)->exists()) {
            throw new ApiException(ApiErrorCode::ITEM_DUPLICATE_BARCODE, 'El código de barras ya está registrado en esta empresa.', 409);
        }
        if ($company->items()->where('internal_code', $attributes['internal_code'])->exists()) {
            throw new ApiException(ApiErrorCode::ITEM_DUPLICATE_CODE, 'El código interno ya está registrado en esta empresa.', 409);
        }
        $attributes['type'] = ItemType::from($attributes['type']);
        // A product is not registered without its stock; a service has neither stock nor wholesale prices.
        $warehouse = null;
        if ($attributes['type'] === ItemType::PRODUCT) {
            if (! is_array($stock) || bccomp((string) ($stock['quantity'] ?? '0'), '0', 6) <= 0) {
                throw new ApiException(ApiErrorCode::ITEM_STOCK_REQUIRED, 'Indica la bodega y cuántas unidades del producto tienes.', 422);
            }
            $warehouse = $this->inventory->activeWarehouse($company, (string) $stock['warehousePublicId']);
        }

        return DB::transaction(function () use ($company, $attributes, $actor, $warehouse, $stock, $tiers, $brand, $barcode): Item {
            $item = $company->items()->create($attributes);
            if ($brand !== null || $barcode !== null) {
                ItemCatalogMetadata::query()->create([
                    'company_id' => $company->id,
                    'item_id' => $item->id,
                    'brand_id' => $brand?->id,
                    'barcode' => $barcode,
                ]);
            }
            if ($warehouse !== null) {
                $this->stock->execute($item, $warehouse, (string) $stock['quantity'], $actor, initial: true);
                $this->priceTiers->execute($item, $tiers);
            }
            $this->taxes->inheritFromCategory($item);
            $item->load(['taxes.definition', 'category', 'catalogMetadata.brand', 'stocks.warehouse.establishment', 'priceTiers']);
            $this->audit->record($actor, $company, 'item.created', $item, null, [...$item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'price_includes_tax', 'is_active', 'category_id']), 'brand_id' => $brand?->id, 'barcode' => $barcode]);

            return $item;
        });
    }
}
