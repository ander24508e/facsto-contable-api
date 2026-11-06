<?php

namespace App\Domains\Withholdings\Services;

use App\Domains\Companies\Models\Company;

/**
 * Whether a company withholds, from its own fiscal profile. RIMPE taxpayers do not withhold unless the SRI
 * designated them (they then have an agent resolution number); companies (third RUC digit 6 or 9), natural
 * persons required to keep accounts and special taxpayers do.
 */
final class WithholdingAgentStatus
{
    /** @return array{canWithhold: bool, reason: string} */
    public function of(Company $company): array
    {
        $settings = $company->fiscalSettings;
        $designated = filled($settings?->withholding_agent_number) || filled($settings?->special_taxpayer_number);
        $reason = match (true) {
            filled($settings?->special_taxpayer_number) => 'SPECIAL_TAXPAYER',
            filled($settings?->withholding_agent_number) => 'DESIGNATED_AGENT',
            $settings?->rimpe_type !== null => 'RIMPE',
            in_array(substr((string) $company->ruc, 2, 1), ['6', '9'], true) => 'COMPANY',
            (bool) $settings?->accounting_required => 'ACCOUNTING_REQUIRED',
            default => 'NOT_REQUIRED',
        };

        return ['canWithhold' => $designated || in_array($reason, ['COMPANY', 'ACCOUNTING_REQUIRED'], true), 'reason' => $reason];
    }

    public function canWithhold(Company $company): bool
    {
        return $this->of($company)['canWithhold'];
    }
}
