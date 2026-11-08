<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Suma a la empresa un usuario que ya tiene cuenta (o reactiva su membresía) con uno o varios roles. */
final class AddCompanyMemberAction
{
    public function __construct(private readonly CompanyRoles $roles) {}

    /** @param list<string> $rolePublicIds */
    public function execute(Company $company, User $actor, User $user, array $rolePublicIds): CompanyMembership
    {
        return DB::transaction(function () use ($company, $actor, $user, $rolePublicIds): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            $belongsToAnotherCompany = CompanyMembership::query()
                ->where('user_id', $user->id)
                ->where('company_id', '<>', $lockedCompany->id)
                ->exists();
            if ($belongsToAnotherCompany) {
                throw new ApiException(ApiErrorCode::USER_BELONGS_TO_ANOTHER_COMPANY, 'El usuario ya pertenece a otra empresa.', 409);
            }

            $membership = CompanyMembership::query()
                ->where('company_id', $lockedCompany->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership?->is_active) {
                throw new ApiException(ApiErrorCode::MEMBERSHIP_ALREADY_ACTIVE, 'El usuario ya es miembro activo de esta empresa.', 409);
            }

            if ($membership) {
                $this->roles->assertCanManage($lockedCompany, $actor, $membership);
                $membership->forceFill(['is_active' => true])->save();
            } else {
                $membership = $lockedCompany->memberships()->create([
                    'user_id' => $user->id,
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }
            $roles = $this->roles->resolve($lockedCompany, $rolePublicIds, $membership->roles()->pluck('company_roles.id')->all());
            $this->roles->assign($lockedCompany, $actor, $membership, $roles);

            return $membership->refresh()->load('roles');
        });
    }
}
