<?php

namespace App\Domains\Sri\Services;

use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;

/**
 * Única decisión de si un comprobante puede salir hacia el SRI (F06).
 * El ambiente (TEST/PRODUCTION) sólo elige el servidor; transmitir exige las tres llaves.
 */
final class SriTransmissionGate
{
    public const TRANSPORT_DISABLED = 'TRANSPORT_DISABLED';

    public const COMPANY_SUBMISSION_DISABLED = 'COMPANY_SUBMISSION_DISABLED';

    public const PROVIDER_NOT_REGISTERED = 'PROVIDER_NOT_REGISTERED';

    /** @return string|null the first blocking reason, or null when transmission is allowed */
    public function blockingReason(?CompanyFiscalSettings $settings): ?string
    {
        return match (true) {
            ! config('sri.transport_enabled') => self::TRANSPORT_DISABLED,
            ! $settings?->sri_submission_enabled => self::COMPANY_SUBMISSION_DISABLED,
            ! config('sri.software_provider.registered') => self::PROVIDER_NOT_REGISTERED,
            default => null,
        };
    }
}
