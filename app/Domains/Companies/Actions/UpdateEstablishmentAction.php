<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\Establishment;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateEstablishmentAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(Establishment $establishment, array $attributes): Establishment
    {
        $company = $this->companyContext->company();

        return DB::transaction(function () use ($company, $establishment, $attributes): Establishment {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $establishment = Establishment::query()->whereKey($establishment->id)->lockForUpdate()->firstOrFail();

            if ($establishment->company_id !== $company->id) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'El establecimiento no pertenece a la empresa activa.', 404);
            }

            if (isset($attributes['code']) && $company->establishments()->where('code', $attributes['code'])->where('id', '!=', $establishment->id)->exists()) {
                throw ValidationException::withMessages(['code' => ['El código ya está registrado para esta empresa.']]);
            }

            $isMain = $attributes['is_main'] ?? null;
            unset($attributes['is_main']);
            if ($isMain === true) {
                $company->establishments()->where('is_main', true)->where('id', '!=', $establishment->id)->update(['is_main' => false]);
            }

            $establishment->fill($attributes);
            if ($isMain !== null) {
                $establishment->is_main = $isMain;
                if ($isMain) {
                    $establishment->is_active = true;
                }
            }
            $establishment->save();

            if ($isMain === true && ! $establishment->emissionPoints()->where('is_default', true)->exists()) {
                $point = $establishment->emissionPoints()->where('is_active', true)->orderBy('code')->first();
                $point?->forceFill(['is_default' => true])->save();
            } elseif ($isMain === true) {
                $establishment->emissionPoints()->where('is_default', true)->where('is_active', false)->update(['is_active' => true]);
            }

            return $establishment->refresh();
        });
    }
}
