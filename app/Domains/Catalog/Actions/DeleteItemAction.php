<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Sales\Models\SaleDetail;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an item for good, with its stock, movements, wholesale prices and photo. One that is in a
 * sale is part of the fiscal history and can only be deactivated.
 */
final class DeleteItemAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Item $item, User $actor): void
    {
        $company = $this->context->company();
        if ($item->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'El artículo no existe en la empresa activa.', 404);
        }
        if (SaleDetail::query()->where('company_id', $company->id)->where('item_id', $item->id)->exists()) {
            throw new ApiException(ApiErrorCode::ITEM_IN_USE, 'El artículo está en ventas; solo puedes desactivarlo.', 409);
        }

        $imagePath = $item->image_path;
        DB::transaction(function () use ($item, $actor, $company): void {
            $before = $item->only(['type', 'internal_code', 'name', 'sale_price', 'is_active']);
            $item->taxes()->detach();
            $item->delete();
            $this->audit->record($actor, $company, 'item.deleted', $item, $before, []);
        });
        if ($imagePath !== null) {
            Storage::disk('private')->delete($imagePath);
        }
    }
}
