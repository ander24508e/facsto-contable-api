<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\EmissionPoint;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Validation\ValidationException;

final class UpdateEmissionPointAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(EmissionPoint $point, array $attributes): EmissionPoint
    {
        $company = $this->companyContext->company();

        if ($point->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'El punto de emisión no pertenece a la empresa activa.', 404);
        }

        if (isset($attributes['code']) && $point->establishment->emissionPoints()->where('code', $attributes['code'])->where('id', '!=', $point->id)->exists()) {
            throw ValidationException::withMessages(['code' => ['El código ya existe en este establecimiento.']]);
        }

        $point->fill($attributes)->save();

        return $point->refresh();
    }
}
