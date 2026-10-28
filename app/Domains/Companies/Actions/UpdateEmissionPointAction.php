<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\EmissionPoint;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateEmissionPointAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(EmissionPoint $point, array $attributes): EmissionPoint
    {
        $company = $this->companyContext->company();

        return DB::transaction(function () use ($company, $point, $attributes): EmissionPoint {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $point = EmissionPoint::query()->whereKey($point->id)->lockForUpdate()->firstOrFail();

            if ($point->company_id !== $company->id) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'El punto de emisión no pertenece a la empresa activa.', 404);
            }

            if (isset($attributes['code']) && $point->establishment->emissionPoints()->where('code', $attributes['code'])->where('id', '!=', $point->id)->exists()) {
                throw ValidationException::withMessages(['code' => ['El código ya existe en este establecimiento.']]);
            }

            $isDefault = $attributes['is_default'] ?? null;
            unset($attributes['is_default']);
            if ($isDefault === true) {
                $point->establishment->emissionPoints()->where('is_default', true)->where('id', '!=', $point->id)->update(['is_default' => false]);
            }

            $point->fill($attributes);
            if ($isDefault !== null) {
                $point->is_default = $isDefault;
                if ($isDefault) {
                    $point->is_active = true;
                }
            }
            $point->save();

            return $point->refresh();
        });
    }
}
