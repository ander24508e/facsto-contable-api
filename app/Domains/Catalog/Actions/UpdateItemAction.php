<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemCatalogMetadata;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Actions\SetItemStockAction;
use App\Domains\Inventory\Actions\SyncItemPriceTiersAction;
use App\Domains\Inventory\Services\InventoryLookup;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateItemAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
        private readonly InventoryLookup $inventory,
        private readonly SetItemStockAction $stock,
        private readonly SyncItemPriceTiersAction $priceTiers,
    ) {}

    public function execute(Item $item, array $attributes, User $actor): Item
    {
        $company = $this->context->company();
        $stock = $attributes['stock'] ?? null;
        $metadataChanges = [];
        if (array_key_exists('brand_public_id', $attributes)) {
            $brandPublicId = $attributes['brand_public_id'];
            unset($attributes['brand_public_id']);
            if ($brandPublicId === null) {
                $metadataChanges['brand_id'] = null;
            } else {
                $brand = $company->brands()->where('public_id', $brandPublicId)->first();
                $existingBrandId = $item->catalogMetadata()->value('brand_id');
                if (! $brand || (! $brand->is_active && $brand->id !== $existingBrandId)) {
                    throw new ApiException(ApiErrorCode::BRAND_NOT_FOUND, 'La marca no está activa en la empresa actual.', 422);
                }
                $metadataChanges['brand_id'] = $brand->id;
            }
        }
        if (array_key_exists('barcode', $attributes)) {
            $barcode = trim((string) ($attributes['barcode'] ?? '')) ?: null;
            unset($attributes['barcode']);
            if ($barcode !== null && ItemCatalogMetadata::query()->where('company_id', $company->id)->where('barcode', $barcode)->where('item_id', '<>', $item->id)->exists()) {
                throw new ApiException(ApiErrorCode::ITEM_DUPLICATE_BARCODE, 'El código de barras ya está registrado en esta empresa.', 409);
            }
            $metadataChanges['barcode'] = $barcode;
        }
        $tiers = array_key_exists('price_tiers', $attributes) ? ($attributes['price_tiers'] ?? []) : null;
        unset($attributes['stock'], $attributes['price_tiers']);
        if (array_key_exists('category_public_id', $attributes)) {
            $categoryPublicId = $attributes['category_public_id'];
            unset($attributes['category_public_id']);
            if ($categoryPublicId === null) {
                $attributes['category_id'] = null;
            } else {
                $category = $company->categories()->where('public_id', $categoryPublicId)->where('is_active', true)->first();
                if (! $category) {
                    throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no está activa en la empresa actual.', 422);
                }
                $attributes['category_id'] = $category->id;
            }
        }
        if ($item->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'El artículo no existe en la empresa activa.', 404);
        }
        if (isset($attributes['internal_code']) && $company->items()->where('internal_code', $attributes['internal_code'])->where('id', '<>', $item->id)->exists()) {
            throw new ApiException(ApiErrorCode::ITEM_DUPLICATE_CODE, 'El código interno ya está registrado en esta empresa.', 409);
        }
        if (isset($attributes['type'])) {
            $attributes['type'] = ItemType::from($attributes['type']);
        }

        $type = $attributes['type'] ?? $item->type;
        $warehouse = $type === ItemType::PRODUCT && is_array($stock) ? $this->inventory->activeWarehouse($company, (string) $stock['warehousePublicId']) : null;

        return DB::transaction(function () use ($item, $attributes, $actor, $company, $warehouse, $stock, $tiers, $type, $metadataChanges): Item {
            if ($warehouse !== null) {
                $this->stock->execute($item, $warehouse, (string) $stock['quantity'], $actor);
            }
            if ($tiers !== null) {
                $this->priceTiers->execute($item, $type === ItemType::PRODUCT ? $tiers : []);
            }
            $before = $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'price_includes_tax', 'is_active', 'category_id']);
            $metadataBefore = $item->catalogMetadata()->first()?->only(['brand_id', 'barcode']);
            $item->fill($attributes)->save();
            if ($metadataChanges !== []) {
                ItemCatalogMetadata::query()->updateOrCreate(['company_id' => $company->id, 'item_id' => $item->id], $metadataChanges);
            }
            $item->refresh();
            $metadataAfter = $item->catalogMetadata()->first()?->only(['brand_id', 'barcode']);
            $this->audit->record($actor, $company, 'item.updated', $item, [...$before, 'catalogMetadata' => $metadataBefore], [...$item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'price_includes_tax', 'is_active', 'category_id']), 'catalogMetadata' => $metadataAfter]);

            return $item->load(['taxes.definition', 'category', 'catalogMetadata.brand', 'stocks.warehouse.establishment', 'priceTiers']);
        });
    }
}
