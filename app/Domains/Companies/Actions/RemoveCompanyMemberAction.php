<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Desactiva a un usuario de la empresa; nunca al último Administrador activo. */
final class RemoveCompanyMemberAction
{
    public function __construct(private readonly CompanyRoles $roles) {}

    public function execute(Company $company, CompanyMembership $membership, User $actor): void
    {
        DB::transaction(function () use ($company, $membership, $actor): void {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $lockedMembership = CompanyMembership::query()
                ->where('company_id', $lockedCompany->id)
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->roles->assertCanManage($lockedCompany, $actor, $lockedMembership);

            if (! $lockedMembership->is_active) {
                return;
            }

            if ($lockedMembership->isAdministrator()) {
                $this->roles->assertKeepsAdministrator($lockedCompany, $lockedMembership);
            }

            $lockedMembership->forceFill(['is_active' => false])->save();
        });
    }
}
