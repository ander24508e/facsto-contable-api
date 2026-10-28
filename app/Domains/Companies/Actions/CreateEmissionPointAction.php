<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Companies\Models\Establishment;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateEmissionPointAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(string $establishmentPublicId, array $attributes): EmissionPoint
    {
        $company = $this->companyContext->company();

        return DB::transaction(function () use ($company, $establishmentPublicId, $attributes): EmissionPoint {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $establishment = Establishment::query()
                ->where('company_id', $company->id)
                ->where('public_id', $establishmentPublicId)
                ->lockForUpdate()
                ->first();

            if (! $establishment) {
                throw new ApiException(ApiErrorCode::ESTABLISHMENT_NOT_FOUND, 'El establecimiento no pertenece a la empresa activa.', 404);
            }

            if ($establishment->emissionPoints()->where('code', $attributes['code'])->exists()) {
                throw ValidationException::withMessages(['code' => ['El código ya existe en este establecimiento.']]);
            }

            $isDefault = $attributes['is_default'] ?? ! $establishment->emissionPoints()->where('is_default', true)->exists();
            unset($attributes['is_default']);
            if ($isDefault) {
                $establishment->emissionPoints()->where('is_default', true)->update(['is_default' => false]);
                $attributes['is_active'] = true;
            }

            return $establishment->emissionPoints()->create([
                ...$attributes,
                'company_id' => $company->id,
                'is_default' => $isDefault,
            ]);
        });
    }
}
