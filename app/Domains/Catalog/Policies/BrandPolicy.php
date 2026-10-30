<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;

final class BrandPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->activeMember($user);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->activeMember($user) && $brand->company_id === $this->context->company()->id;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->canWrite($user) && $brand->company_id === $this->context->company()->id;
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->update($user, $brand);
    }

    private function activeMember(User $user): bool
    {
        return $this->context->company()->memberships()->where('user_id', $user->id)->where('is_active', true)->exists();
    }

    private function canWrite(User $user): bool
    {
        return $this->context->company()->memberships()->where('user_id', $user->id)->where('is_active', true)
            ->whereIn('role', [CompanyRole::OWNER->value, CompanyRole::ADMIN->value, CompanyRole::BILLER->value])->exists();
    }
}
