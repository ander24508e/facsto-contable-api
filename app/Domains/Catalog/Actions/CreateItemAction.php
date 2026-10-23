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

final class CreateItemAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(array $attributes, User $actor): Item
    {
        $company = $this->context->company();
        $categoryPublicId = $attributes['category_public_id'] ?? null;
        unset($attributes['category_public_id']);
        if ($categoryPublicId !== null) {
            $category = $company->categories()->where('public_id', $categoryPublicId)->where('is_active', true)->first();
            if (! $category) {
                throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no está activa en la empresa actual.', 422);
            }
            $attributes['category_id'] = $category->id;
        }
        if ($company->items()->where('internal_code', $attributes['internal_code'])->exists()) {
            throw new ApiException(ApiErrorCode::ITEM_DUPLICATE_CODE, 'El código interno ya está registrado en esta empresa.', 409);
        }
        $attributes['type'] = ItemType::from($attributes['type']);

        return DB::transaction(function () use ($company, $attributes, $actor): Item {
            $item = $company->items()->create($attributes)->load(['taxes', 'category']);
            $this->audit->record($actor, $company, 'item.created', $item, null, $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'is_active', 'category_id']));

            return $item;
        });
    }
}
