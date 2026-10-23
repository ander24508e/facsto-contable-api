<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateCompanyAction
{
    public function execute(User $owner, array $attributes): Company
    {
        return DB::transaction(function () use ($owner, $attributes): Company {
            $company = Company::query()->create($attributes);
            $company->memberships()->create([
                'user_id' => $owner->id,
                'role' => CompanyRole::OWNER,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            return $company->refresh()->load('memberships');
        });
    }
}
