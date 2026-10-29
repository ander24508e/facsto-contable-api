<?php

namespace App\Domains\Customers\Policies;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Customers\Models\Customer;
use App\Models\User;

final class CustomerPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->activeMember($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->activeMember($user) && $customer->company_id === $this->context->company()->id;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->canWrite($user) && $customer->company_id === $this->context->company()->id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
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
