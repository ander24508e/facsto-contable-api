<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Stores the item photo on the private disk and replaces the previous one. */
final class UpdateItemImageAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Item $item, UploadedFile $image, User $actor): Item
    {
        $company = $this->context->company();
        if ($item->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'El artículo no existe en la empresa activa.', 404);
        }

        $path = 'companies/'.$company->public_id.'/items/'.$item->public_id.'/'.Str::uuid().'.'.$image->extension();
        $previousPath = $item->image_path;
        Storage::disk('private')->putFileAs(dirname($path), $image, basename($path));

        try {
            $item->forceFill(['image_path' => $path])->save();
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        if ($previousPath !== null) {
            Storage::disk('private')->delete($previousPath);
        }
        $this->audit->record($actor, $company, 'item.image_updated', $item, ['hasImage' => $previousPath !== null], ['hasImage' => true]);

        return $item->refresh()->load(['taxes.definition', 'category']);
    }
}
