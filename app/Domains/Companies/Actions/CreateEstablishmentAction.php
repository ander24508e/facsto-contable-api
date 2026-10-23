<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Establishment;
use Illuminate\Validation\ValidationException;

final class CreateEstablishmentAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(array $attributes): Establishment
    {
        $company = $this->companyContext->company();

        if ($company->establishments()->where('code', $attributes['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['El código ya está registrado para esta empresa.']]);
        }

        return $company->establishments()->create($attributes);
    }
}
