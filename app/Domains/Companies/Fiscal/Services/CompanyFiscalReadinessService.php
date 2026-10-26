<?php

namespace App\Domains\Companies\Fiscal\Services;

use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Companies\Fiscal\Enums\CompanySetupState;
use App\Domains\Companies\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class CompanyFiscalReadinessService
{
    public function __construct(private readonly EcuadorRucValidator $rucValidator) {}

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
        ];
    }

    public function issuerIsReady(Company $company): bool
    {
        return is_string($company->ruc)
            && $this->rucValidator->isStructurallyValid($company->ruc)
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

        // The certificate must sign for this issuer: its RUC must still match the company RUC.
        return $certificate !== null
            && $certificate->subject_ruc !== null
            && $certificate->subject_ruc === $company->ruc
            && $certificate->valid_from->lessThanOrEqualTo(Carbon::now())
            && $certificate->valid_until->greaterThan(Carbon::now())
            && filled($certificate->storage_path)
            && Storage::disk('private')->exists($certificate->storage_path);
    }
}
