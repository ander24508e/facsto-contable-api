<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class AddCompanyMemberAction
{
    public function execute(Company $company, User $actor, User $user, CompanyRole $role): CompanyMembership
    {
        return DB::transaction(function () use ($company, $actor, $user, $role): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $actorRole = $lockedCompany->memberships()
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->value('role');

            $actorCompanyRole = $actorRole instanceof CompanyRole ? $actorRole : ($actorRole ? CompanyRole::from($actorRole) : null);
            if (! $actorCompanyRole || ! $actorCompanyRole->canManage($role)) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'No puedes asignar ese rol.', 403);
            }

            $membership = CompanyMembership::query()
                ->where('company_id', $lockedCompany->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership && ! $actorCompanyRole->canManage($membership->role)) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'No puedes administrar esta membresía.', 403);
            }

            if ($membership?->is_active) {
                throw new ApiException(ApiErrorCode::MEMBERSHIP_ALREADY_ACTIVE, 'El usuario ya es miembro activo de esta empresa.', 409);
            }

            if ($membership) {
                $membership->forceFill(['role' => $role, 'is_active' => true])->save();

                return $membership->refresh();
            }

            return $lockedCompany->memberships()->create([
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        });
    }
}
