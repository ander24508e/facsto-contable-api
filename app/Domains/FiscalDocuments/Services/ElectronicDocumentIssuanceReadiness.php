<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\Certificates\Services\CertificateReadabilityChecker;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Companies\Fiscal\Services\CompanyFiscalReadinessService;
use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;
use App\Domains\Companies\Models\Company;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleInvoiceReadinessService;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * The single barrier before a fiscal number exists. A company works freely with drafts, but no
 * sequential, number or access key is assigned until it can really continue to XML → signature → SRI.
 */
final class ElectronicDocumentIssuanceReadiness
{
    public function __construct(
        private readonly CompanyFiscalReadinessService $company,
        private readonly CertificateReadabilityChecker $certificates,
        private readonly SaleInvoiceReadinessService $sale,
        private readonly EcuadorRucValidator $rucValidator,
    ) {}

    /** @return list<string> company-level reasons that block issuance */
    public function companyPending(Company $company): array
    {
        $pending = [];
        if ($company->status !== CompanyStatus::ACTIVE) {
            $pending[] = 'company_inactive';
        }
        if (! $this->company->issuerIsReady($company)) {
            $pending[] = 'issuer';
        }
        if (! $this->company->emissionIsReady($company)) {
            $pending[] = 'emission';
        }
        $certificate = $company->digitalCertificates()->where('is_active', true)->first();
        if (! $this->company->certificateIsReady($company)) {
            $pending[] = 'certificate';
        } elseif (! $this->certificates->isReadable($certificate)) {
            $pending[] = 'certificate_unreadable';
        }

        return $pending;
    }

    public function assertCanIssue(Company $company, Sale $sale): void
    {
        $pending = $this->companyPending($company);
        if ($pending !== []) {
            throw new ApiException(ApiErrorCode::FISCAL_SETUP_INCOMPLETE, $this->companyMessage($pending), 409, ['pending' => $pending]);
        }
        // Anexo 26: the provider RUC is frozen in every document, so it must be valid before numbering.
        if (! $this->rucValidator->isStructurallyValid((string) config('sri.software_provider.ruc'))) {
            throw new ApiException(ApiErrorCode::SRI_PROVIDER_NOT_CONFIGURED, 'Falta configurar el RUC válido del proveedor de software (SRI_SOFTWARE_PROVIDER_RUC).', 409);
        }
        $saleStatus = $this->sale->summary($sale);
        if (! $saleStatus['ready']) {
            throw new ApiException(ApiErrorCode::SALE_NOT_READY_FOR_INVOICE, 'La venta todavía tiene datos pendientes para facturación.', 422, ['pending' => $saleStatus['pending']]);
        }
    }

    /** @param list<string> $pending */
    private function companyMessage(array $pending): string
    {
        return match (true) {
            in_array('issuer', $pending, true), in_array('emission', $pending, true) => 'Completa los datos del emisor y la estructura de emisión antes de emitir comprobantes.',
            in_array('certificate_unreadable', $pending, true) => 'No se pudo abrir el certificado electrónico guardado. Vuelve a cargarlo para emitir comprobantes.',
            in_array('certificate', $pending, true) => 'Carga un certificado electrónico vigente del RUC de la empresa para emitir comprobantes. Puedes seguir guardando ventas.',
            default => 'La empresa no está activa para emitir comprobantes.',
        };
    }
}
