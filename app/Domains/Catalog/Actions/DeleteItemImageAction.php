<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\Storage;

final class DeleteItemImageAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Item $item, User $actor): Item
    {
        $company = $this->context->company();
        if ($item->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'El artículo no existe en la empresa activa.', 404);
        }

        $path = $item->image_path;
        if ($path !== null) {
            $item->forceFill(['image_path' => null])->save();
            Storage::disk('private')->delete($path);
            $this->audit->record($actor, $company, 'item.image_deleted', $item, ['hasImage' => true], ['hasImage' => false]);
        }

        return $item->refresh()->load(['taxes.definition', 'category']);
    }
}
