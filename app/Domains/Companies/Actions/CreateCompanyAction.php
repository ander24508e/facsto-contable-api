<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Services\CompanyRoles;
use App\Domains\Taxes\Services\CompanyTaxCatalogSynchronizer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Una empresa nueva nace con su rol fijo «Administrador», asignado al usuario que la crea. */
final class CreateCompanyAction
{
    public function __construct(private readonly CompanyTaxCatalogSynchronizer $taxes, private readonly CompanyRoles $roles) {}

    public function execute(User $owner, array $attributes): Company
    {
        return DB::transaction(function () use ($owner, $attributes): Company {
            $company = Company::query()->create($attributes);
            $membership = $company->memberships()->create([
                'user_id' => $owner->id,
                'is_active' => true,
                'joined_at' => now(),
            ]);
            $this->roles->makeAdministrator($membership);
            // Every company starts with the official rates enabled by default.
            $this->taxes->syncCompany($company);

            return $company->refresh()->load('memberships');
        });
    }
}
