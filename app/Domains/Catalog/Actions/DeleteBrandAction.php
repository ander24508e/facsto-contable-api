<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class DeleteBrandAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Brand $brand, User $actor): void
    {
        $company = $this->context->company();
        if ($brand->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::BRAND_NOT_FOUND, 'La marca no existe en la empresa activa.', 404);
        }
        if ($brand->itemMetadata()->exists()) {
            throw new ApiException(ApiErrorCode::BRAND_IN_USE, 'La marca está asignada a artículos; solo puedes desactivarla.', 409);
        }

        DB::transaction(function () use ($brand, $actor, $company): void {
            $before = $brand->only(['name', 'description', 'is_active']);
            $brand->delete();
            $this->audit->record($actor, $company, 'brand.deleted', $brand, $before, []);
        });
    }
}
