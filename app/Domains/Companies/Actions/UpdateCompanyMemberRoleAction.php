<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateCompanyMemberRoleAction
{
    public function execute(Company $company, CompanyMembership $membership, User $actor, CompanyRole $role): CompanyMembership
    {
        return DB::transaction(function () use ($company, $membership, $actor, $role): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $lockedMembership = CompanyMembership::query()
                ->where('company_id', $lockedCompany->id)
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();
            $actorRole = $lockedCompany->memberships()
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->value('role');

            if (! $actorRole || ! CompanyRole::from($actorRole instanceof CompanyRole ? $actorRole->value : $actorRole)->canManage($lockedMembership->role)
                || ! CompanyRole::from($actorRole instanceof CompanyRole ? $actorRole->value : $actorRole)->canManage($role)) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'No puedes asignar ese rol.', 403);
            }

            if ($lockedMembership->is_active && $lockedMembership->role === CompanyRole::OWNER && $role !== CompanyRole::OWNER) {
                $activeOwners = $lockedCompany->memberships()
                    ->where('is_active', true)
                    ->where('role', CompanyRole::OWNER->value)
                    ->count();

                if ($activeOwners <= 1) {
                    throw new ApiException(ApiErrorCode::LAST_OWNER_REQUIRED, 'La empresa debe conservar al menos un propietario activo.', 409);
                }
            }

            $lockedMembership->forceFill(['role' => $role])->save();

            return $lockedMembership->refresh();
        });
    }
}
