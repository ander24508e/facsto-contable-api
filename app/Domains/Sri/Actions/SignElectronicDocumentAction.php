<?php

namespace App\Domains\Sri\Actions;

use App\Domains\Certificates\DTOs\CertificateMaterial;
use App\Domains\Certificates\Services\CertificateMaterialService;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Services\CompanyFiscalReadinessService;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\DocumentArtifact;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\Signing\Contracts\XmlSigner;
use App\Domains\Sri\Signing\SignatureValidationException;
use App\Domains\Sri\Signing\SignatureValidator;
use App\Domains\Sri\Signing\XmlSigningException;
use App\Domains\Sri\Xml\XmlSchemaValidationException;
use App\Domains\Sri\Xml\XsdValidator;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * F05.2: XML_BUILT → XAdES-BES → independent verification → SIGNED_XML → SIGNED.
 * A signature that FACSTO cannot verify by itself never becomes a SIGNED document.
 */
final class SignElectronicDocumentAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CompanyFiscalReadinessService $readiness,
        private readonly CertificateMaterialService $material,
        private readonly XmlSigner $signer,
        private readonly SignatureValidator $validator,
        private readonly XsdValidator $xsd,
        private readonly DocumentArtifactStorage $artifacts,
        private readonly DocumentStateMachine $states,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(ElectronicDocument $invoice, User $actor): ElectronicDocument
    {
        $company = $this->context->company();
        if ($invoice->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::INVOICE_NOT_FOUND, 'No se encontró la factura.', 404);
        }
        $document = ElectronicDocument::query()->where('company_id', $company->id)->whereKey($invoice->id)->firstOrFail();
        $this->assertBuilt($document);

        $certificate = $company->digitalCertificates()->where('is_active', true)->first();
        if ($certificate === null || ! $this->readiness->certificateIsReady($company)) {
            throw new ApiException(ApiErrorCode::FISCAL_SETUP_INCOMPLETE, 'Carga un certificado electrónico vigente del RUC de la empresa para firmar el comprobante.', 409, ['pending' => ['certificate']]);
        }

        $unsigned = $this->artifacts->latest($document, DocumentArtifactType::UNSIGNED_XML);
        $unsignedXml = $this->artifacts->read($unsigned);
        if (! hash_equals($unsigned->sha256, hash('sha256', $unsignedXml))) {
            throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_INTEGRITY_FAILED, 'El XML generado del comprobante fue alterado; vuelve a generarlo.', 409, ['artifact_type' => DocumentArtifactType::UNSIGNED_XML->value]);
        }

        // The JVM runs outside any database lock; the state is re-checked before persisting.
        try {
            $signedXml = $this->material->using($certificate, function (CertificateMaterial $material) use ($unsignedXml): string {
                $signed = $this->signer->sign($unsignedXml, $material);
                $this->validator->validate($signed, $material->certificateDer);

                return $signed;
            });
            $this->xsd->validate($signedXml, (string) config('sri.invoice.xsd_path'));
        } catch (RuntimeException $exception) {
            throw $this->signingError($document, $exception);
        }
        // The SRI rejects bigger submissions: such a document never becomes SIGNED nor reaches the queue.
        $maxBytes = (int) config('sri.max_document_bytes', 320 * 1024);
        if (strlen($signedXml) > $maxBytes) {
            throw new ApiException(ApiErrorCode::INVOICE_SIGNING_FAILED, 'El comprobante firmado supera el tamaño máximo que acepta el SRI (320 KB). Divide la venta en varias facturas.', 422, [
                'reason_code' => 'SIGNED_XML_TOO_LARGE',
                'sizeBytes' => strlen($signedXml),
                'maxBytes' => $maxBytes,
            ]);
        }

        $stored = null;
        try {
            return DB::transaction(function () use ($document, $company, $actor, $unsigned, $signedXml, $certificate, &$stored): ElectronicDocument {
                $locked = ElectronicDocument::query()->where('company_id', $company->id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
                $this->assertBuilt($locked);
                if ($this->artifacts->latest($locked, DocumentArtifactType::UNSIGNED_XML)->id !== $unsigned->id) {
                    throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'El XML del comprobante cambió mientras se firmaba.', 409);
                }

                $stored = $this->artifacts->store($locked, DocumentArtifactType::SIGNED_XML, $signedXml);
                $locked = $this->states->transition($locked, DocumentStatus::SIGNED, ['signed_at' => now(), 'last_error_code' => null]);
                $this->audit->record($actor, $company, 'invoice.signed', $locked, null, [
                    'status' => $locked->status->value,
                    'artifact_type' => DocumentArtifactType::SIGNED_XML->value,
                    'artifact_version' => $stored->version,
                    'sha256' => $stored->sha256,
                    'unsigned_sha256' => $unsigned->sha256,
                    'certificate_public_id' => $certificate->public_id,
                ]);

                return $locked->load(['lines.taxes', 'taxes', 'paymentMethods']);
            });
        } catch (\Throwable $exception) {
            if ($stored instanceof DocumentArtifact) {
                $this->artifacts->discardFile($stored);
            }
            throw $exception;
        }
    }

    private function assertBuilt(ElectronicDocument $document): void
    {
        if ($document->status !== DocumentStatus::XML_BUILT) {
            throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'Sólo un comprobante con XML generado puede firmarse.', 409, [
                'from' => $document->status->value,
                'to' => DocumentStatus::SIGNED->value,
            ]);
        }
    }

    private function signingError(ElectronicDocument $document, RuntimeException $exception): ApiException
    {
        [$code, $reason, $message, $status] = match (true) {
            $exception instanceof XmlSigningException => [ApiErrorCode::INVOICE_SIGNING_FAILED, $exception->reasonCode, $exception->getMessage(), 422],
            $exception instanceof SignatureValidationException => [ApiErrorCode::INVOICE_SIGNATURE_INVALID, $exception->reasonCode, $exception->getMessage(), 422],
            $exception instanceof XmlSchemaValidationException => [ApiErrorCode::INVOICE_SIGNATURE_INVALID, 'SIGNED_XML_SCHEMA_INVALID', 'El XML firmado no cumple el esquema oficial del SRI.', 422],
            $exception->getMessage() === 'CERTIFICATE_MATERIAL_UNAVAILABLE' => [ApiErrorCode::FISCAL_SETUP_INCOMPLETE, 'certificate_unreadable', 'No se pudo abrir el certificado electrónico guardado. Vuelve a cargarlo para firmar.', 409],
            default => throw $exception,
        };
        Log::warning('Invoice could not be signed.', ['invoice_public_id' => $document->public_id, 'reason' => $reason]);

        return $code === ApiErrorCode::FISCAL_SETUP_INCOMPLETE
            ? new ApiException($code, $message, $status, ['pending' => [$reason]])
            : new ApiException($code, $message, $status, ['reason_code' => $reason]);
    }
}
