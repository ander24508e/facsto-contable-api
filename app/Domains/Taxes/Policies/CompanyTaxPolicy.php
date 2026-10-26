<?php

namespace App\Domains\Taxes\Policies;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Taxes\Models\CompanyTax;
use App\Models\User;

/** Every member consults taxes; only OWNER and ADMIN change which official rates the company applies. */
final class CompanyTaxPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->membership($user)->exists();
    }

    public function manage(User $user): bool
    {
        return $this->membership($user)->whereIn('role', [CompanyRole::OWNER->value, CompanyRole::ADMIN->value])->exists();
    }

    public function update(User $user, CompanyTax $tax): bool
    {
        return $this->manage($user) && $tax->company_id === $this->context->company()->id;
    }

    private function membership(User $user)
    {
        return $this->context->company()->memberships()->where('user_id', $user->id)->where('is_active', true);
    }
}
