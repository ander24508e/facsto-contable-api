<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Companies\Models\Company;

/**
 * A new company starts in the SRI test environment with normal emission. Nothing else is
 * pre-filled: establishments and emission points are the client's data, entered in the welcome flow.
 */
final class ProvisionDefaultFiscalSettingsAction
{
    public function execute(Company $company): void
    {
        $company->fiscalSettings()->firstOrNew()->fill([
            'sri_environment' => SriEnvironment::TEST,
            'emission_type' => EmissionType::NORMAL,
        ])->save();
    }
}
