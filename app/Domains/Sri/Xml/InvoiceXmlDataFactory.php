<?php

namespace App\Domains\Sri\Xml;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\DTOs\InvoiceLineXmlData;
use App\Domains\Sri\DTOs\InvoicePaymentXmlData;
use App\Domains\Sri\DTOs\InvoiceTaxXmlData;
use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Mappers\SriCurrencyMapper;
use App\Domains\Sri\Mappers\SriDocumentTypeMapper;
use App\Domains\Sri\Mappers\SriEmissionTypeMapper;
use App\Domains\Sri\Mappers\SriEnvironmentMapper;
use App\Domains\Sri\Mappers\SriIdentificationTypeMapper;

final class InvoiceXmlDataFactory
{
    public function __construct(
        private readonly SriEnvironmentMapper $environments,
        private readonly SriEmissionTypeMapper $emissions,
        private readonly SriDocumentTypeMapper $documentTypes,
        private readonly SriIdentificationTypeMapper $identifications,
        private readonly SriCurrencyMapper $currencies,
        private readonly DecimalFormatter $decimals,
    ) {}

    public function make(ElectronicDocument $document): InvoiceXmlData
    {
        $document->loadMissing(['lines.taxes', 'taxes', 'paymentMethods']);

        $lines = $document->lines->map(fn ($line): InvoiceLineXmlData => new InvoiceLineXmlData(
            itemCode: (string) $line->item_code,
            auxiliaryCode: $line->auxiliary_code,
            description: (string) $line->description,
            quantity: $this->decimals->quantity((string) $line->quantity),
            unitPrice: $this->decimals->unitPrice((string) $line->unit_price),
            discount: $this->decimals->money((string) $line->discount),
            priceTotalWithoutTax: $this->decimals->money((string) $line->price_total_without_tax),
            taxes: $line->taxes->map(fn ($tax): InvoiceTaxXmlData => new InvoiceTaxXmlData(
                taxCode: (string) $tax->tax_code,
                percentageCode: (string) $tax->percentage_code,
                percentage: $this->decimals->percentage((string) $tax->percentage),
                taxableBase: $this->decimals->taxBase((string) $tax->taxable_base),
                taxAmount: $this->decimals->taxAmount((string) $tax->tax_amount),
            ))->all(),
        ))->all();

        $taxes = $document->taxes->map(fn ($tax): InvoiceTaxXmlData => new InvoiceTaxXmlData(
            taxCode: (string) $tax->tax_code,
            percentageCode: (string) $tax->percentage_code,
            percentage: $this->decimals->percentage((string) $tax->percentage),
            taxableBase: $this->decimals->taxBase((string) $tax->taxable_base),
            taxAmount: $this->decimals->taxAmount((string) $tax->tax_amount),
        ))->all();

        $payments = $document->paymentMethods->map(fn ($payment): InvoicePaymentXmlData => new InvoicePaymentXmlData(
            methodCode: (string) $payment->method_code,
            amount: $this->decimals->money((string) $payment->amount),
            term: $payment->term === null ? null : $this->decimals->quantity((string) $payment->term),
            timeUnit: $payment->time_unit,
        ))->all();

        return new InvoiceXmlData(
            xmlVersion: (string) $document->xml_version,
            environmentCode: $this->environments->map((string) $document->sri_environment),
            emissionTypeCode: $this->emissions->map((string) $document->emission_type),
            issuerLegalName: (string) $document->issuer_legal_name,
            issuerCommercialName: $document->issuer_commercial_name,
            issuerRuc: (string) $document->issuer_ruc,
            accessKey: (string) $document->access_key,
            documentTypeCode: $this->documentTypes->map($document->document_type),
            establishmentCode: (string) $document->establishment_code,
            emissionPointCode: (string) $document->emission_point_code,
            sequential: (string) $document->sequential,
            issuerMainAddress: (string) $document->issuer_main_address,
            issueDate: $document->issue_date->format('d/m/Y'),
            establishmentAddress: $document->establishment_address,
            specialTaxpayerNumber: $document->special_taxpayer_number,
            accountingRequired: $document->accounting_required === null ? null : (bool) $document->accounting_required,
            customerIdentificationTypeCode: $this->identifications->map((string) $document->customer_identification_type),
            customerName: (string) $document->customer_name,
            customerIdentification: (string) $document->customer_identification,
            customerAddress: $document->customer_address,
            totalWithoutTaxes: $this->decimals->money((string) $document->total_without_taxes),
            totalDiscount: $this->decimals->money((string) $document->discount_total),
            taxes: $taxes,
            tip: $this->decimals->money((string) $document->tip_amount),
            total: $this->decimals->money((string) $document->total),
            currency: $this->currencies->map((string) $document->currency),
            payments: $payments,
            lines: $lines,
            customerEmail: $document->customer_email,
            withholdingAgentNumber: $document->withholding_agent_number,
            rimpeType: $document->rimpe_type,
            providerRuc: (string) $document->provider_ruc,
            documentType: $document->document_type,
        );
    }
}
