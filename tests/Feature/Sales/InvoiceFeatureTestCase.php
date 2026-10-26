<?php

namespace Tests\Feature\Sales;

use App\Domains\Companies\Models\Company;
use App\Models\User;

abstract class InvoiceFeatureTestCase extends SalesTestCase
{
    /** Issuer and emission structure configured, without a signing certificate. */
    protected function preparableCompany(User $user, string $companyRuc = '1799999999001', string $issuerRuc = '1790016919001'): Company
    {
        $company = $this->createCompany($user, $companyRuc);
        $company->forceFill(['ruc' => $issuerRuc, 'legal_name' => 'Emisor válido', 'main_address' => 'Quito'])->save();
        $company->fiscalSettings()->create(['accounting_required' => false, 'sri_environment' => 'TEST', 'emission_type' => 'NORMAL']);
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito', 'is_active' => true, 'is_main' => true]);
        $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Principal', 'is_active' => true, 'is_default' => true]);

        return $company;
    }

    /** Fully able to issue: a readable certificate whose RUC matches the issuer. */
    protected function readyCompany(User $user, string $companyRuc = '1799999999001', string $issuerRuc = '1790016919001'): Company
    {
        $company = $this->preparableCompany($user, $companyRuc, $issuerRuc);
        $this->installReadyCertificate($company);

        return $company;
    }
}
