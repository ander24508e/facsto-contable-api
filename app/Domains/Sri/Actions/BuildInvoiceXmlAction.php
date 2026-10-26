<?php

namespace App\Domains\Sri\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\DocumentArtifact;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\Xml\InvoiceXmlBuilder;
use App\Domains\Sri\Xml\InvoiceXmlDataFactory;
use App\Domains\Sri\Xml\InvoiceXmlPreflightException;
use App\Domains\Sri\Xml\InvoiceXmlPreflightValidator;
use App\Domains\Sri\Xml\XmlSchemaValidationException;
use App\Domains\Sri\Xml\XsdValidator;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BuildInvoiceXmlAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly InvoiceXmlPreflightValidator $preflight,
        private readonly InvoiceXmlDataFactory $dataFactory,
        private readonly InvoiceXmlBuilder $builder,
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

        $storedArtifact = null;
        try {
            return DB::transaction(function () use ($invoice, $company, $actor, &$storedArtifact): ElectronicDocument {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $document = ElectronicDocument::query()
                    ->where('company_id', $company->id)
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->with(['lines.taxes', 'taxes', 'paymentMethods'])
                    ->firstOrFail();

                try {
                    $this->preflight->validate($document);
                } catch (InvoiceXmlPreflightException $exception) {
                    throw new ApiException(
                        ApiErrorCode::VALIDATION_FAILED,
                        'La factura no está lista para generar el XML.',
                        422,
                        ['reason_code' => $exception->reasonCode],
                    );
                }

                $data = $this->dataFactory->make($document);
                $xml = $this->builder->build($data);
                try {
                    $this->xsd->validate($xml, (string) config('sri.invoice.xsd_path'));
                } catch (XmlSchemaValidationException $exception) {
                    Log::warning('Invoice unsigned XML did not pass SRI XSD validation.', [
                        'invoice_public_id' => $document->public_id,
                        'errors' => $exception->errors,
                    ]);
                    throw new ApiException(
                        ApiErrorCode::VALIDATION_FAILED,
                        'El XML generado no cumple el esquema oficial del SRI.',
                        422,
                        ['reason_code' => 'XML_SCHEMA_INVALID'],
                    );
                }

                $storedArtifact = $this->artifacts->store($document, DocumentArtifactType::UNSIGNED_XML, $xml);
                $document = $this->states->transition($document, DocumentStatus::XML_BUILT, ['xml_built_at' => now(), 'last_error_code' => null]);
                $this->audit->record($actor, $company, 'invoice.xml_built', $document, null, [
                    'status' => $document->status->value,
                    'artifact_type' => DocumentArtifactType::UNSIGNED_XML->value,
                    'artifact_version' => $storedArtifact->version,
                    'sha256' => $storedArtifact->sha256,
                ]);

                return $document->load(['lines.taxes', 'taxes', 'paymentMethods']);
            });
        } catch (\Throwable $exception) {
            if ($storedArtifact instanceof DocumentArtifact) {
                $this->artifacts->discardFile($storedArtifact);
            }
            throw $exception;
        }
    }
}
