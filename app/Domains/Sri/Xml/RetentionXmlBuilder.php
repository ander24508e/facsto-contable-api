<?php

namespace App\Domains\Sri\Xml;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Mappers\SriDocumentTypeMapper;
use App\Domains\Sri\Mappers\SriEmissionTypeMapper;
use App\Domains\Sri\Mappers\SriEnvironmentMapper;
use App\Domains\Sri\Mappers\SriIdentificationTypeMapper;
use DOMDocument;
use DOMElement;

/**
 * comprobanteRetencion 2.0.0 (codDoc 07) from the frozen document: the withheld supplier, one support
 * document (the supplier's invoice of the purchase) with its taxes, the withholdings and its payment.
 */
final class RetentionXmlBuilder
{
    public function __construct(
        private readonly XmlTextNormalizer $text,
        private readonly DecimalFormatter $decimals,
        private readonly SriEnvironmentMapper $environments,
        private readonly SriEmissionTypeMapper $emissions,
        private readonly SriDocumentTypeMapper $documentTypes,
        private readonly SriIdentificationTypeMapper $identifications,
    ) {}

    public function build(ElectronicDocument $retention): string
    {
        $retention->loadMissing(['taxes', 'paymentMethods', 'withholdings']);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElement('comprobanteRetencion');
        $root->setAttribute('id', 'comprobante');
        $root->setAttribute('version', (string) $retention->xml_version);
        $document->appendChild($root);

        $this->tributaria($document, $root, $retention);
        $this->info($document, $root, $retention);
        $support = $this->element($document, $this->element($document, $root, 'docsSustento'), 'docSustento');
        $this->add($document, $support, 'codSustento', (string) $retention->support_code);
        $this->add($document, $support, 'codDocSustento', (string) $retention->support_document_code);
        $this->add($document, $support, 'numDocSustento', str_replace('-', '', (string) $retention->support_document_number));
        $this->add($document, $support, 'fechaEmisionDocSustento', $retention->support_document_date->format('d/m/Y'));
        if (filled($retention->support_authorization_number)) {
            $this->add($document, $support, 'numAutDocSustento', (string) $retention->support_authorization_number);
        }
        // 01: local payment to a resident.
        $this->add($document, $support, 'pagoLocExt', '01');
        $this->add($document, $support, 'totalSinImpuestos', $this->decimals->money((string) $retention->total_without_taxes));
        $this->add($document, $support, 'importeTotal', $this->decimals->money((string) $retention->total));

        $taxes = $this->element($document, $support, 'impuestosDocSustento');
        foreach ($retention->taxes as $tax) {
            $row = $this->element($document, $taxes, 'impuestoDocSustento');
            $this->add($document, $row, 'codImpuestoDocSustento', (string) $tax->tax_code);
            $this->add($document, $row, 'codigoPorcentaje', (string) $tax->percentage_code);
            $this->add($document, $row, 'baseImponible', $this->decimals->money((string) $tax->taxable_base));
            $this->add($document, $row, 'tarifa', $this->decimals->percentage((string) $tax->percentage));
            $this->add($document, $row, 'valorImpuesto', $this->decimals->money((string) $tax->tax_amount));
        }

        $withholdings = $this->element($document, $support, 'retenciones');
        foreach ($retention->withholdings as $withholding) {
            $row = $this->element($document, $withholdings, 'retencion');
            $this->add($document, $row, 'codigo', (string) $withholding->tax_code);
            $this->add($document, $row, 'codigoRetencion', (string) $withholding->withholding_code);
            $this->add($document, $row, 'baseImponible', $this->decimals->money((string) $withholding->taxable_base));
            $this->add($document, $row, 'porcentajeRetener', $this->decimals->percentage((string) $withholding->percentage));
            $this->add($document, $row, 'valorRetenido', $this->decimals->money((string) $withholding->amount));
        }

        $payments = $this->element($document, $support, 'pagos');
        foreach ($retention->paymentMethods as $payment) {
            $row = $this->element($document, $payments, 'pago');
            $this->add($document, $row, 'formaPago', (string) $payment->method_code);
            $this->add($document, $row, 'total', $this->decimals->money((string) $payment->amount));
        }

        $additional = $this->element($document, $root, 'infoAdicional');
        $this->field($document, $additional, 'RUC Proveedor', (string) $retention->provider_ruc);
        if (filled($retention->customer_email)) {
            $this->field($document, $additional, 'Email', (string) $retention->customer_email);
        }

        return $document->saveXML();
    }

    private function tributaria(DOMDocument $document, DOMElement $root, ElectronicDocument $retention): void
    {
        $info = $this->element($document, $root, 'infoTributaria');
        $this->add($document, $info, 'ambiente', $this->environments->map((string) $retention->sri_environment));
        $this->add($document, $info, 'tipoEmision', $this->emissions->map((string) $retention->emission_type));
        $this->add($document, $info, 'razonSocial', (string) $retention->issuer_legal_name);
        if (filled($retention->issuer_commercial_name)) {
            $this->add($document, $info, 'nombreComercial', (string) $retention->issuer_commercial_name);
        }
        $this->add($document, $info, 'ruc', (string) $retention->issuer_ruc);
        $this->add($document, $info, 'claveAcceso', (string) $retention->access_key);
        $this->add($document, $info, 'codDoc', $this->documentTypes->map($retention->document_type));
        $this->add($document, $info, 'estab', (string) $retention->establishment_code);
        $this->add($document, $info, 'ptoEmi', (string) $retention->emission_point_code);
        $this->add($document, $info, 'secuencial', (string) $retention->sequential);
        $this->add($document, $info, 'dirMatriz', (string) $retention->issuer_main_address);
        if (filled($retention->withholding_agent_number)) {
            $number = ltrim((string) $retention->withholding_agent_number, '0');
            $this->add($document, $info, 'agenteRetencion', $number === '' ? '0' : $number);
        }
        // The 2.0.0 schema only admits the RIMPE entrepreneur legend.
        if ($retention->rimpe_type === 'ENTREPRENEUR') {
            $this->add($document, $info, 'contribuyenteRimpe', "CONTRIBUYENTE R\u{00C9}GIMEN RIMPE");
        }
    }

    private function info(DOMDocument $document, DOMElement $root, ElectronicDocument $retention): void
    {
        $info = $this->element($document, $root, 'infoCompRetencion');
        $this->add($document, $info, 'fechaEmision', $retention->issue_date->format('d/m/Y'));
        if (filled($retention->establishment_address)) {
            $this->add($document, $info, 'dirEstablecimiento', (string) $retention->establishment_address);
        }
        if (filled($retention->special_taxpayer_number)) {
            $this->add($document, $info, 'contribuyenteEspecial', (string) $retention->special_taxpayer_number);
        }
        $this->add($document, $info, 'obligadoContabilidad', $retention->accounting_required ? 'SI' : 'NO');
        $this->add($document, $info, 'tipoIdentificacionSujetoRetenido', $this->identifications->map((string) $retention->customer_identification_type));
        $this->add($document, $info, 'parteRel', $retention->related_party ? 'SI' : 'NO');
        $this->add($document, $info, 'razonSocialSujetoRetenido', (string) $retention->customer_name);
        $this->add($document, $info, 'identificacionSujetoRetenido', (string) $retention->customer_identification);
        $this->add($document, $info, 'periodoFiscal', (string) $retention->fiscal_period);
    }

    private function element(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElement($name);
        $parent->appendChild($element);

        return $element;
    }

    private function add(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $this->text->appendElement($document, $parent, $name, $value);
    }

    private function field(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElement('campoAdicional');
        $element->setAttribute('nombre', $name);
        $element->appendChild($document->createTextNode($this->text->normalize($value)));
        $parent->appendChild($element);
    }
}
