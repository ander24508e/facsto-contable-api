<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateBrandAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Brand $brand, array $attributes, User $actor): Brand
    {
        $company = $this->context->company();
        if ($brand->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::BRAND_NOT_FOUND, 'La marca no existe en la empresa activa.', 404);
        }
        $name = trim($attributes['name'] ?? $brand->name);
        if ($company->brands()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('id', '<>', $brand->id)->exists()) {
            throw new ApiException(ApiErrorCode::BRAND_DUPLICATE, 'Ya existe una marca con ese nombre en esta empresa.', 409);
        }

        return DB::transaction(function () use ($brand, $attributes, $actor, $company, $name): Brand {
            $before = $brand->only(['name', 'description', 'is_active']);
            $brand->fill([...$attributes, 'name' => $name])->save();
            $this->audit->record($actor, $company, 'brand.updated', $brand, $before, $brand->only(['name', 'description', 'is_active']));

            return $brand->refresh();
        });
    }
}
