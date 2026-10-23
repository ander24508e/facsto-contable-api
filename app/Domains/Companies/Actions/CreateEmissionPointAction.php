<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Companies\Models\Establishment;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Validation\ValidationException;

final class CreateEmissionPointAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(string $establishmentPublicId, array $attributes): EmissionPoint
    {
        $company = $this->companyContext->company();
        $establishment = Establishment::query()
            ->where('company_id', $company->id)
            ->where('public_id', $establishmentPublicId)
            ->first();

        if (! $establishment) {
            throw new ApiException(ApiErrorCode::ESTABLISHMENT_NOT_FOUND, 'El establecimiento no pertenece a la empresa activa.', 404);
        }

        if ($establishment->emissionPoints()->where('code', $attributes['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['El código ya existe en este establecimiento.']]);
        }

        return $establishment->emissionPoints()->create([
            ...$attributes,
            'company_id' => $company->id,
        ]);
    }
}
