<?php

namespace App\Domains\Sri\DTOs;

use App\Domains\FiscalDocuments\Enums\DocumentType;

final readonly class InvoiceXmlData
{
    /** @param list<InvoiceLineXmlData> $lines @param list<InvoiceTaxXmlData> $taxes @param list<InvoicePaymentXmlData> $payments */
    public function __construct(
        public string $xmlVersion,
        public string $environmentCode,
        public string $emissionTypeCode,
        public string $issuerLegalName,
        public ?string $issuerCommercialName,
        public string $issuerRuc,
        public string $accessKey,
        public string $documentTypeCode,
        public string $establishmentCode,
        public string $emissionPointCode,
        public string $sequential,
        public string $issuerMainAddress,
        public string $issueDate,
        public ?string $establishmentAddress,
        public ?string $specialTaxpayerNumber,
        public ?bool $accountingRequired,
        public string $customerIdentificationTypeCode,
        public string $customerName,
        public string $customerIdentification,
        public ?string $customerAddress,
        public string $totalWithoutTaxes,
        public string $totalDiscount,
        public array $taxes,
        public string $tip,
        public string $total,
        public string $currency,
        public array $payments,
        public array $lines,
        public ?string $customerEmail,
        public ?string $withholdingAgentNumber,
        public ?string $rimpeType,
        public string $providerRuc,
        public DocumentType $documentType,
    ) {}
}
