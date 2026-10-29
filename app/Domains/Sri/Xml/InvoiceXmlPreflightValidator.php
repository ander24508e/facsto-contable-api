<?php

namespace App\Domains\Sri\Xml;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\Mappers\SriDocumentTypeMapper;
use App\Domains\Sri\Mappers\SriEmissionTypeMapper;
use App\Domains\Sri\Mappers\SriEnvironmentMapper;
use App\Domains\Sri\Mappers\SriIdentificationTypeMapper;
use LogicException;

final class InvoiceXmlPreflightValidator
{
    public function __construct(
        private readonly SriDocumentTypeMapper $documentTypes,
        private readonly SriEnvironmentMapper $environments,
        private readonly SriEmissionTypeMapper $emissions,
        private readonly SriIdentificationTypeMapper $identifications,
        private readonly DocumentStateMachine $states,
    ) {}

    public function validate(ElectronicDocument $document): void
    {
        $document->loadMissing(['lines.taxes', 'taxes', 'paymentMethods', 'modifiedDocument']);

        // NUMBERED, or RETURNED / NOT_AUTHORIZED / FAILED rebuilt with the same number and access key.
        if (! $this->states->canTransition($document->status, DocumentStatus::XML_BUILT)) {
            $this->fail('DOCUMENT_NOT_BUILDABLE');
        }
        $isCreditNote = $document->document_type === DocumentType::CREDIT_NOTE;
        if ($isCreditNote && ($document->modifiedDocument === null || blank($document->reason))) {
            $this->fail('CREDIT_NOTE_REFERENCE_REQUIRED');
        }
        if ($document->xml_version !== '1.1.0') {
            $this->fail('XML_VERSION_UNSUPPORTED');
        }
        if (blank($document->issuer_ruc) || ! preg_match('/^\d{13}$/', (string) $document->issuer_ruc)) {
            $this->fail('ISSUER_RUC_REQUIRED');
        }
        if (blank($document->issuer_legal_name)) {
            $this->fail('ISSUER_LEGAL_NAME_REQUIRED');
        }
        if (blank($document->issuer_main_address)) {
            $this->fail('ISSUER_MAIN_ADDRESS_REQUIRED');
        }

        try {
            $this->environments->map((string) $document->sri_environment);
            $this->emissions->map((string) $document->emission_type);
            $this->documentTypes->map($document->document_type);
            $this->identifications->map((string) $document->customer_identification_type);
        } catch (LogicException) {
            $this->fail('SRI_MAPPING_UNSUPPORTED');
        }

        if (! preg_match('/^\d{49}$/', (string) $document->access_key)) {
            $this->fail('ACCESS_KEY_INVALID');
        }
        if (! preg_match('/^\d{3}$/', (string) $document->establishment_code)) {
            $this->fail('ESTABLISHMENT_CODE_INVALID');
        }
        if (! preg_match('/^\d{3}$/', (string) $document->emission_point_code)) {
            $this->fail('EMISSION_POINT_CODE_INVALID');
        }
        if (! preg_match('/^\d{9}$/', (string) $document->sequential)) {
            $this->fail('SEQUENTIAL_INVALID');
        }
        if ($document->lines->isEmpty()) {
            $this->fail('INVOICE_LINES_REQUIRED');
        }
        foreach ($document->lines as $line) {
            if (! $this->isDecimal($line->quantity) || bccomp((string) $line->quantity, '0', 6) <= 0) {
                $this->fail('INVOICE_LINE_QUANTITY_INVALID');
            }
            if (! $this->isDecimal($line->unit_price) || bccomp((string) $line->unit_price, '0', 6) < 0) {
                $this->fail('INVOICE_LINE_UNIT_PRICE_INVALID');
            }
            if (! $this->isDecimal($line->price_total_without_tax) || bccomp((string) $line->price_total_without_tax, '0', 2) < 0) {
                $this->fail('INVOICE_LINE_TAXABLE_TOTAL_INVALID');
            }
        }

        // A credit note has no payments: it returns value against the invoice it modifies.
        if (! $isCreditNote) {
            if ($document->paymentMethods->isEmpty()) {
                $this->fail('INVOICE_PAYMENT_METHODS_REQUIRED');
            }
            $paymentTotal = '0.00';
            foreach ($document->paymentMethods as $payment) {
                if (! $this->isDecimal($payment->amount)) {
                    $this->fail('INVOICE_PAYMENT_AMOUNT_INVALID');
                }
                $paymentTotal = bcadd($paymentTotal, (string) $payment->amount, 2);
            }
            if (! $this->isDecimal($document->total) || bccomp($paymentTotal, (string) $document->total, 2) !== 0) {
                $this->fail('INVOICE_PAYMENT_TOTAL_MISMATCH');
            }
        }

        if (! preg_match('/^\d{13}$/', (string) $document->provider_ruc)) {
            $this->fail('SRI_PROVIDER_NOT_CONFIGURED');
        }
        if ($document->rimpe_type !== null && ! in_array($document->rimpe_type, ['ENTREPRENEUR', 'POPULAR_BUSINESS'], true)) {
            $this->fail('RIMPE_TYPE_UNSUPPORTED');
        }
        if ($document->customer_identification_type === 'FINAL_CONSUMER' && bccomp((string) $document->total, '50.00', 2) > 0) {
            $this->fail('FINAL_CONSUMER_AMOUNT_EXCEEDED');
        }
    }

    private function isDecimal(mixed $value): bool
    {
        return is_string($value) || is_int($value)
            ? preg_match('/^-?\d+(?:\.\d+)?$/', (string) $value) === 1
            : false;
    }

    private function fail(string $reasonCode): never
    {
        throw new InvoiceXmlPreflightException($reasonCode);
    }
}
