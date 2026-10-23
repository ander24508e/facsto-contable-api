<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;

final class TaxPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->company()->memberships()->where('user_id', $user->id)->where('is_active', true)->exists();
    }
}
