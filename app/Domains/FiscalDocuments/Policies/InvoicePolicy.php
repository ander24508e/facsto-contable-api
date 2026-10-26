<?php

namespace App\Domains\FiscalDocuments\Policies;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;

final class InvoicePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->member($user);
    }

    public function view(User $user, ElectronicDocument $invoice): bool
    {
        return $this->member($user) && $invoice->company_id === $this->context->company()->id;
    }

    public function create(User $user): bool
    {
        return $this->context->company()->memberships()->where('user_id', $user->id)->where('is_active', true)
            ->whereIn('role', [CompanyRole::OWNER->value, CompanyRole::ADMIN->value, CompanyRole::BILLER->value])->exists();
    }

    public function process(User $user, ElectronicDocument $invoice): bool
    {
        return $invoice->company_id === $this->context->company()->id && $this->create($user);
    }

    private function member(User $user): bool
    {
        return $this->context->company()->memberships()->where('user_id', $user->id)->where('is_active', true)->exists();
    }
}
