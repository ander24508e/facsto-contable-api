<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class ReactivateCompanyMemberAction
{
    public function execute(Company $company, CompanyMembership $membership, User $actor): CompanyMembership
    {
        return DB::transaction(function () use ($company, $membership, $actor): CompanyMembership {
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

            if (! $actorRole || ! CompanyRole::from($actorRole instanceof CompanyRole ? $actorRole->value : $actorRole)->canManage($lockedMembership->role)) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'No puedes administrar esta membresÃ­a.', 403);
            }

            if ($lockedMembership->is_active) {
                throw new ApiException(ApiErrorCode::MEMBERSHIP_ALREADY_ACTIVE, 'La membresÃ­a ya estÃ¡ activa.', 409);
            }

            $lockedMembership->forceFill(['is_active' => true])->save();

            return $lockedMembership->refresh();
        });
    }
}
