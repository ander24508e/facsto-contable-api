<?php

namespace App\Domains\Companies\Policies;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Companies\Models\Company;
use App\Models\User;

final class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::ACTIVE
            && $company->memberships()->where('user_id', $user->id)->where('is_active', true)->exists();
    }

    public function update(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::ACTIVE
            && $company->memberships()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereIn('role', [CompanyRole::OWNER->value, CompanyRole::ADMIN->value])
                ->exists();
    }
}
