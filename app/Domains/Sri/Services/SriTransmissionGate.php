<?php

namespace App\Domains\Sri\Services;

use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;

/**
 * Única decisión de si un comprobante puede salir hacia el SRI (F06).
 * El ambiente (TEST/PRODUCTION) elige el servidor; además, el registro del proveedor de software
 * sólo se exige en PRODUCTION: el ambiente de pruebas no tiene efecto tributario.
 */
final class SriTransmissionGate
{
    public const TRANSPORT_DISABLED = 'TRANSPORT_DISABLED';

    public const COMPANY_SUBMISSION_DISABLED = 'COMPANY_SUBMISSION_DISABLED';

    public const PROVIDER_NOT_REGISTERED = 'PROVIDER_NOT_REGISTERED';

    /**
     * @param  string|null  $environment  ambiente congelado en el comprobante; por defecto, el de la empresa
     * @return string|null the first blocking reason, or null when transmission is allowed
     */
    public function blockingReason(?CompanyFiscalSettings $settings, ?string $environment = null): ?string
    {
        $environment ??= $settings?->sri_environment?->value;

        return match (true) {
            ! config('sri.transport_enabled') => self::TRANSPORT_DISABLED,
            ! $settings?->sri_submission_enabled => self::COMPANY_SUBMISSION_DISABLED,
            $environment !== SriEnvironment::TEST->value && ! config('sri.software_provider.registered') => self::PROVIDER_NOT_REGISTERED,
            default => null,
        };
    }
}
