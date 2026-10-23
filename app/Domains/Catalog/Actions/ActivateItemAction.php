<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class ActivateItemAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Item $item, User $actor): Item
    {
        if ($item->company_id !== $this->context->company()->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'El artículo no existe en la empresa activa.', 404);
        }

        return DB::transaction(function () use ($item, $actor): Item {
            $before = $item->only(['is_active']);
            $item->forceFill(['is_active' => true])->save();
            $item->refresh();
            $this->audit->record($actor, $this->context->company(), 'item.activated', $item, $before, $item->only(['is_active']));

            return $item->load('taxes');
        });
    }
}
