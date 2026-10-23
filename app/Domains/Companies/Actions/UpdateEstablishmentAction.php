<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Establishment;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Validation\ValidationException;

final class UpdateEstablishmentAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(Establishment $establishment, array $attributes): Establishment
    {
        $company = $this->companyContext->company();

        if ($establishment->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'El establecimiento no pertenece a la empresa activa.', 404);
        }

        if (isset($attributes['code']) && $company->establishments()->where('code', $attributes['code'])->where('id', '!=', $establishment->id)->exists()) {
            throw ValidationException::withMessages(['code' => ['El código ya está registrado para esta empresa.']]);
        }

        $establishment->fill($attributes)->save();

        return $establishment->refresh();
    }
}
