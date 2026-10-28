<?php

namespace App\Domains\Companies\Fiscal\Services;

use App\Domains\Companies\Models\Company;

/** Checklist that must be complete before a company can switch on electronic invoicing (SRI submission). */
final class ElectronicInvoicingRequirements
{
    public function __construct(private readonly CompanyFiscalReadinessService $readiness) {}

    /** @return array{companyData: bool, certificate: bool, emission: bool, environment: bool} */
    public function check(Company $company): array
    {
        return [
            'companyData' => $this->readiness->issuerIsReady($company),
            'certificate' => $this->readiness->certificateIsReady($company),
            'emission' => $this->readiness->emissionIsReady($company),
            'environment' => $company->fiscalSettings?->sri_environment !== null,
        ];
    }

    /** @return list<string> */
    public function pending(Company $company): array
    {
        return array_keys(array_filter($this->check($company), fn (bool $done): bool => ! $done));
    }
}
