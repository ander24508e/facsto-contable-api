<?php

namespace App\Domains\Companies\Fiscal\Services;

use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Companies\Fiscal\Enums\CompanySetupState;
use App\Domains\Companies\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class CompanyFiscalReadinessService
{
    public function __construct(private readonly EcuadorTaxIdentifierValidator $rucValidator) {}

    public function state(Company $company): CompanySetupState
    {
        if (! $this->issuerIsReady($company)) {
            return CompanySetupState::ISSUER_REQUIRED;
        }

        if (! $this->emissionIsReady($company)) {
            return CompanySetupState::EMISSION_REQUIRED;
        }

        if (! $this->certificateIsReady($company)) {
            return CompanySetupState::CERTIFICATE_REQUIRED;
        }

        return CompanySetupState::READY;
    }

    public function summary(Company $company): array
    {
        $state = $this->state($company);
        $issuerReady = $this->issuerIsReady($company);
        $emissionReady = $issuerReady && $this->emissionIsReady($company);
        $certificateReady = $emissionReady && $this->certificateIsReady($company);

        return [
            'state' => $state->value,
            'ready' => $state === CompanySetupState::READY,
            'canIssueDocuments' => $state === CompanySetupState::READY,
            'issuerReady' => $issuerReady,
            'emissionReady' => $emissionReady,
            'certificateReady' => $certificateReady,
            'deferred' => $company->fiscalSettings?->setup_deferred_at !== null,
        ];
    }

    public function issuerIsReady(Company $company): bool
    {
        return is_string($company->ruc)
            && $this->rucValidator->isValidRuc($company->ruc)
            && filled($company->legal_name)
            && filled($company->main_address);
    }

    public function emissionIsReady(Company $company): bool
    {
        $settings = $company->fiscalSettings;
        $establishment = $company->establishments()
            ->where('is_active', true)
            ->where('is_main', true)
            ->first();

        return $settings?->sri_environment !== null
            && $settings?->emission_type !== null
            && $establishment !== null
            && $establishment->emissionPoints()
                ->where('is_active', true)
                ->where('is_default', true)
                ->exists();
    }

    public function certificateIsReady(Company $company): bool
    {
        $certificate = $company->digitalCertificates()
            ->where('is_active', true)
            ->where('status', CertificateStatus::VALID->value)
            ->first();

        return $certificate !== null
            && $certificate->valid_from->lessThanOrEqualTo(Carbon::now())
            && $certificate->valid_until->greaterThan(Carbon::now())
            && Storage::disk('private')->exists($certificate->storage_path);
    }
}
