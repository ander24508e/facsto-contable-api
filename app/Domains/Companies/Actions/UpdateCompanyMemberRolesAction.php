<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/** Cambia los roles de un usuario (uno o varios). */
final class UpdateCompanyMemberRolesAction
{
    public function __construct(private readonly CompanyRoles $roles, private readonly AuditRecorder $audit) {}

    /** @param list<string> $rolePublicIds */
    public function execute(Company $company, CompanyMembership $membership, User $actor, array $rolePublicIds): CompanyMembership
    {
        return DB::transaction(function () use ($company, $membership, $actor, $rolePublicIds): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $lockedMembership = CompanyMembership::query()
                ->where('company_id', $lockedCompany->id)
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $lockedMembership->roles()->pluck('company_roles.name')->all();
            $roles = $this->roles->resolve($lockedCompany, $rolePublicIds, $lockedMembership->roles()->pluck('company_roles.id')->all());
            $this->roles->assign($lockedCompany, $actor, $lockedMembership, $roles);

            $this->audit->record($actor, $lockedCompany, 'company.member_roles_updated', $lockedMembership->user, ['roles' => $before], [
                'roles' => $roles->pluck('name')->all(),
            ]);

            return $lockedMembership->refresh()->load('roles');
        });
    }
}
