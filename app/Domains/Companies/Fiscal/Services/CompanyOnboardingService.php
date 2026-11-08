<?php

namespace App\Domains\Companies\Fiscal\Services;

use App\Domains\Companies\Fiscal\Enums\CompanySetupState;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;

/**
 * The welcome flow is shown once to each of the first two administrators of a
 * company (the Condore operator who provisions it and the client's admin).
 * Employees and later administrators never see it.
 */
final class CompanyOnboardingService
{
    private const ONBOARDING_ADMIN_SEATS = 2;

    public function __construct(private readonly CompanyFiscalReadinessService $readiness) {}

    public function isRequiredFor(Company $company, User $user): bool
    {
        $membership = $this->membership($company, $user);

        return $membership !== null
            && $membership->onboarding_completed_at === null
            && $this->holdsOnboardingSeat($company, $membership)
            && $this->readiness->state($company) !== CompanySetupState::READY;
    }

    /** Setup summary for the company plus whether this user must see the welcome flow. */
    public function status(Company $company, User $user): array
    {
        return [
            ...$this->readiness->summary($company),
            'onboardingRequired' => $this->isRequiredFor($company, $user),
        ];
    }

    public function membership(Company $company, User $user): ?CompanyMembership
    {
        return $company->memberships()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    private function holdsOnboardingSeat(Company $company, CompanyMembership $membership): bool
    {
        if (! $membership->isAdministrator()) {
            return false;
        }

        // The first Administradores of the company (by membership) see the welcome flow.
        return $company->memberships()
            ->whereHas('roles', fn ($roles) => $roles->where('company_roles.is_fixed', true))
            ->orderBy('id')
            ->limit(self::ONBOARDING_ADMIN_SEATS)
            ->pluck('id')
            ->contains($membership->id);
    }
}
