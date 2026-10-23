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
        if ($company->items()->where('internal_code', $attributes['internal_code'])->exists()) {
            throw new ApiException(ApiErrorCode::ITEM_DUPLICATE_CODE, 'El código interno ya está registrado en esta empresa.', 409);
        }
        $attributes['type'] = ItemType::from($attributes['type']);

        return DB::transaction(function () use ($company, $attributes, $actor): Item {
            $item = $company->items()->create($attributes)->load('taxes');
            $this->audit->record($actor, $company, 'item.created', $item, null, $item->only(['type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'is_active']));

            return $item;
        });
    }
}
