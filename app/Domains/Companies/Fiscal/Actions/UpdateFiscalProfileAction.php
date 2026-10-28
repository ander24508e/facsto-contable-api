<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class UpdateFiscalProfileAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{accountingRequired?: bool, specialTaxpayerNumber?: ?string, withholdingAgentNumber?: ?string, artisanQualificationNumber?: ?string, rimpeType?: ?string} $attributes */
    public function execute(array $attributes, User $actor): CompanyFiscalSettings
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $attributes, $actor): CompanyFiscalSettings {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $settings = $company->fiscalSettings()->firstOrNew();
            $before = [
                'accountingRequired' => $settings->accounting_required,
                'specialTaxpayerNumber' => $settings->special_taxpayer_number,
                'withholdingAgentNumber' => $settings->withholding_agent_number,
                'artisanQualificationNumber' => $settings->artisan_qualification_number,
                'rimpeType' => $settings->rimpe_type?->value,
            ];

            $columns = [
                'accounting_required' => $attributes['accountingRequired'] ?? null,
                'special_taxpayer_number' => $attributes['specialTaxpayerNumber'] ?? null,
                'withholding_agent_number' => $attributes['withholdingAgentNumber'] ?? null,
                'artisan_qualification_number' => $attributes['artisanQualificationNumber'] ?? null,
                'rimpe_type' => $attributes['rimpeType'] ?? null,
            ];
            foreach ([
                'accountingRequired' => 'accounting_required',
                'specialTaxpayerNumber' => 'special_taxpayer_number',
                'withholdingAgentNumber' => 'withholding_agent_number',
                'artisanQualificationNumber' => 'artisan_qualification_number',
                'rimpeType' => 'rimpe_type',
            ] as $input => $column) {
                if (array_key_exists($input, $attributes)) {
                    $columns[$column] = $attributes[$input];
                } else {
                    unset($columns[$column]);
                }
            }
            $settings->fill($columns);
            $settings->save();

            $this->audit->record($actor, $company, 'company.fiscal_profile_updated', $company, $before, [
                'accountingRequired' => $settings->accounting_required,
                'specialTaxpayerNumber' => $settings->special_taxpayer_number,
                'withholdingAgentNumber' => $settings->withholding_agent_number,
                'artisanQualificationNumber' => $settings->artisan_qualification_number,
                'rimpeType' => $settings->rimpe_type?->value,
            ]);

            return $settings;
        });
    }
}
