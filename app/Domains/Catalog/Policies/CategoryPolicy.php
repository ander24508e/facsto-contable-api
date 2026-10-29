<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;

final class CategoryPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->activeMember($user);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->activeMember($user) && $category->company_id === $this->context->company()->id;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->canWrite($user) && $category->company_id === $this->context->company()->id;
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->update($user, $category);
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
