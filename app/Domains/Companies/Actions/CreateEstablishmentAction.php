<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\Establishment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateEstablishmentAction
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function execute(array $attributes): Establishment
    {
        $company = $this->companyContext->company();

        return DB::transaction(function () use ($company, $attributes): Establishment {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($company->establishments()->where('code', $attributes['code'])->exists()) {
                throw ValidationException::withMessages(['code' => ['El código ya está registrado para esta empresa.']]);
            }

            $isMain = $attributes['is_main'] ?? ! $company->establishments()->where('is_main', true)->exists();
            unset($attributes['is_main']);

            if ($isMain) {
                $company->establishments()->where('is_main', true)->update(['is_main' => false]);
                $attributes['is_active'] = true;
            }

            return $company->establishments()->create([...$attributes, 'is_main' => $isMain]);
        });
    }
}
