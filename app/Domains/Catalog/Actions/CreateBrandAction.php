<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CreateBrandAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(array $attributes, User $actor): Brand
    {
        $company = $this->context->company();
        $name = trim($attributes['name']);
        if ($company->brands()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new ApiException(ApiErrorCode::BRAND_DUPLICATE, 'Ya existe una marca con ese nombre en esta empresa.', 409);
        }

        return DB::transaction(function () use ($company, $name, $attributes, $actor): Brand {
            $brand = $company->brands()->create(['name' => $name, 'description' => $attributes['description'] ?? null, 'is_active' => true]);
            $this->audit->record($actor, $company, 'brand.created', $brand, null, $brand->only(['name', 'description', 'is_active']));

            return $brand;
        });
    }
}
