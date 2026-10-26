<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Fiscal\Enums\CompanySetupState;
use App\Domains\Companies\Fiscal\Services\CompanyFiscalReadinessService;
use App\Domains\Companies\Fiscal\Services\CompanyOnboardingService;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/** Marks the welcome flow as finished for one administrator; it never reopens afterwards. */
final class CompleteOnboardingAction
{
    public function __construct(
        private readonly CompanyOnboardingService $onboarding,
        private readonly CompanyFiscalReadinessService $readiness,
        private readonly AuditRecorder $audit,
    ) {}

    public function skip(Company $company, User $actor): void
    {
        $this->complete($company, $actor, 'skipped');
    }

    public function completeIfReady(Company $company, User $actor): void
    {
        if ($this->readiness->state($company) === CompanySetupState::READY) {
            $this->complete($company, $actor, 'completed');
        }
    }

    private function complete(Company $company, User $actor, string $outcome): void
    {
        $membership = $this->onboarding->membership($company, $actor);
        if ($membership === null || $membership->onboarding_completed_at !== null) {
            return;
        }

        DB::transaction(function () use ($company, $actor, $membership, $outcome): void {
            $membership = CompanyMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($membership->onboarding_completed_at !== null) {
                return;
            }

            $membership->forceFill(['onboarding_completed_at' => now()])->save();
            $this->audit->record($actor, $company, 'company.onboarding_'.$outcome, $company, null, [
                'userPublicId' => $actor->public_id,
                'outcome' => $outcome,
            ]);
        });
    }
}
