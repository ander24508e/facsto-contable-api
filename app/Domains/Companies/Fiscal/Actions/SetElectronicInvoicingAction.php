<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Services\ElectronicInvoicingRequirements;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * «Activar facturación electrónica»: the company's own switch for sending documents to the SRI
 * (company_fiscal_settings.sri_submission_enabled). It only turns on with the whole checklist done.
 */
final class SetElectronicInvoicingAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ElectronicInvoicingRequirements $requirements,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(bool $enabled, User $actor): Company
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $enabled, $actor): Company {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($enabled && ($pending = $this->requirements->pending($company)) !== []) {
                throw new ApiException(ApiErrorCode::ELECTRONIC_INVOICING_REQUIREMENTS_PENDING, 'Completa todos los requisitos antes de activar la facturación electrónica.', 409, ['pending' => $pending]);
            }

            $settings = $company->fiscalSettings()->firstOrNew();
            $before = (bool) $settings->sri_submission_enabled;
            $settings->forceFill(['sri_submission_enabled' => $enabled])->save();
            $this->audit->record($actor, $company, $enabled ? 'company.electronic_invoicing_enabled' : 'company.electronic_invoicing_disabled', $company, ['enabled' => $before], ['enabled' => $enabled]);

            return $company->refresh();
        });
    }
}
