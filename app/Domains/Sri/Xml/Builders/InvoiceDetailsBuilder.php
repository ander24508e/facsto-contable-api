<?php

namespace App\Domains\Sri\Xml\Builders;

use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\DecimalFormatter;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class InvoiceDetailsBuilder
{
    public function __construct(
        private readonly XmlTextNormalizer $text,
        private readonly DecimalFormatter $decimals,
    ) {}

    public function append(DOMDocument $document, DOMElement $root, InvoiceXmlData $data): void
    {
        // The credit note schema names the codes codigoInterno / codigoAdicional.
        [$mainCode, $auxiliaryCode] = $data->documentType === DocumentType::CREDIT_NOTE
            ? ['codigoInterno', 'codigoAdicional']
            : ['codigoPrincipal', 'codigoAuxiliar'];
        $details = $document->createElement('detalles');
        $root->appendChild($details);
        foreach ($data->lines as $line) {
            $detail = $document->createElement('detalle');
            $details->appendChild($detail);
            $this->text->appendElement($document, $detail, $mainCode, $line->itemCode);
            if (filled($line->auxiliaryCode)) {
                $this->text->appendElement($document, $detail, $auxiliaryCode, $line->auxiliaryCode);
            }
            $this->text->appendElement($document, $detail, 'descripcion', $line->description);
            $this->text->appendElement($document, $detail, 'cantidad', $this->decimals->quantity($line->quantity));
            $this->text->appendElement($document, $detail, 'precioUnitario', $this->decimals->unitPrice($line->unitPrice));
            $this->text->appendElement($document, $detail, 'descuento', $this->decimals->money($line->discount));
            $this->text->appendElement($document, $detail, 'precioTotalSinImpuesto', $this->decimals->money($line->priceTotalWithoutTax));

            $taxes = $document->createElement('impuestos');
            $detail->appendChild($taxes);
            foreach ($line->taxes as $tax) {
                $taxNode = $document->createElement('impuesto');
                $taxes->appendChild($taxNode);
                $this->text->appendElement($document, $taxNode, 'codigo', $tax->taxCode);
                $this->text->appendElement($document, $taxNode, 'codigoPorcentaje', $tax->percentageCode);
                $this->text->appendElement($document, $taxNode, 'tarifa', $this->decimals->percentage($tax->percentage));
                $this->text->appendElement($document, $taxNode, 'baseImponible', $this->decimals->taxBase($tax->taxableBase));
                $this->text->appendElement($document, $taxNode, 'valor', $this->decimals->taxAmount($tax->taxAmount));
            }
        }
    }
}
