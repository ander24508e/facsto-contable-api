<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Taxes\Services\ItemTaxService;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class SyncItemTaxesAction
{
    public function __construct(private readonly CompanyContext $context, private readonly ItemTaxService $taxes, private readonly AuditRecorder $audit) {}

    public function execute(Item $item, array $taxPublicIds, User $actor): Item
    {
        if ($item->company_id !== $this->context->company()->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'El artículo no existe en la empresa activa.', 404);
        }

        return DB::transaction(function () use ($item, $taxPublicIds, $actor): Item {
            $before = $item->taxes()->pluck('company_taxes.public_id')->all();
            $this->taxes->sync($item, $taxPublicIds);
            $item->refresh()->load('taxes.definition');
            $this->audit->record($actor, $this->context->company(), 'item.taxes_updated', $item, ['taxPublicIds' => $before], ['taxPublicIds' => $item->taxes->pluck('public_id')->all()]);

            return $item;
        });
    }
}
