<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class DeactivateBrandAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Brand $brand, User $actor): Brand
    {
        $company = $this->context->company();
        if ($brand->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::BRAND_NOT_FOUND, 'La marca no existe en la empresa activa.', 404);
        }
        if ($brand->is_active) {
            $brand->forceFill(['is_active' => false])->save();
            $this->audit->record($actor, $company, 'brand.deactivated', $brand, ['is_active' => true], ['is_active' => false]);
        }

        return $brand->refresh();
    }
}
