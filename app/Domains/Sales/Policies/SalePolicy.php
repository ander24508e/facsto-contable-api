<?php

namespace App\Domains\Sales\Policies;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Sales\Models\Sale;
use App\Models\User;

final class SalePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->activeMember($user);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->activeMember($user) && $sale->company_id === $this->context->company()->id;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Sale $sale): bool
    {
        return $this->canWrite($user) && $sale->company_id === $this->context->company()->id;
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
