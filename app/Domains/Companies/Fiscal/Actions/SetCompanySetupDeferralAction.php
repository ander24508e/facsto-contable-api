<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class SetCompanySetupDeferralAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(bool $deferred, User $actor): Company
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $deferred, $actor): Company {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $settings = $company->fiscalSettings()->firstOrNew();
            $wasDeferred = $settings->setup_deferred_at !== null;
            $settings->setup_deferred_at = $deferred ? now() : null;
            $settings->save();

            if ($wasDeferred !== $deferred) {
                $this->audit->record(
                    $actor,
                    $company,
                    $deferred ? 'company.setup_deferred' : 'company.setup_resumed',
                    $company,
                    ['deferred' => $wasDeferred],
                    ['deferred' => $deferred],
                );
            }

            return $company->refresh();
        });
    }
}
