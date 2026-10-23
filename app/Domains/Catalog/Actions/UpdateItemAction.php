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
            $before = $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'is_active']);
            $item->fill($attributes)->save();
            $item->refresh();
            $this->audit->record($actor, $company, 'item.updated', $item, $before, $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'is_active']));

            return $item->load('taxes');
        });
    }
}
