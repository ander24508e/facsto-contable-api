<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class ReactivateCompanyMemberAction
{
    public function __construct(private readonly CompanyRoles $roles) {}

    public function execute(Company $company, CompanyMembership $membership, User $actor): CompanyMembership
    {
        return DB::transaction(function () use ($company, $membership, $actor): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $lockedMembership = CompanyMembership::query()
                ->where('company_id', $lockedCompany->id)
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->roles->assertCanManage($lockedCompany, $actor, $lockedMembership);

            if ($lockedMembership->is_active) {
                throw new ApiException(ApiErrorCode::MEMBERSHIP_ALREADY_ACTIVE, 'La membresía ya está activa.', 409);
            }

            $lockedMembership->forceFill(['is_active' => true])->save();

            return $lockedMembership->refresh();
        });
    }
}
