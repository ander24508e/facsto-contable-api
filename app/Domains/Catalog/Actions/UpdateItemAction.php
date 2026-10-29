<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateItemAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Item $item, array $attributes, User $actor): Item
    {
        $company = $this->context->company();
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

        return DB::transaction(function () use ($item, $attributes, $actor, $company): Item {
            $before = $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'price_includes_tax', 'is_active', 'category_id']);
            $item->fill($attributes)->save();
            $item->refresh();
            $this->audit->record($actor, $company, 'item.updated', $item, $before, $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'price_includes_tax', 'is_active', 'category_id']));

            return $item->load(['taxes.definition', 'category']);
        });
    }
}
