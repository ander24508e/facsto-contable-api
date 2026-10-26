<?php

namespace App\Domains\Sri\Xml\Builders;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\DecimalFormatter;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class InfoFacturaBuilder
{
    public function __construct(
        private readonly XmlTextNormalizer $text,
        private readonly DecimalFormatter $decimals,
        private readonly InvoicePaymentsBuilder $paymentsBuilder,
    ) {}

    public function append(DOMDocument $document, DOMElement $root, InvoiceXmlData $data): void
    {
        $info = $document->createElement('infoFactura');
        $root->appendChild($info);
        $this->text->appendElement($document, $info, 'fechaEmision', $data->issueDate);

        if (filled($data->establishmentAddress)) {
            $this->text->appendElement($document, $info, 'dirEstablecimiento', $data->establishmentAddress);
        }
        if (filled($data->specialTaxpayerNumber)) {
            $this->text->appendElement($document, $info, 'contribuyenteEspecial', $data->specialTaxpayerNumber);
        }
        if ($data->accountingRequired !== null) {
            $this->text->appendElement($document, $info, 'obligadoContabilidad', $data->accountingRequired ? 'SI' : 'NO');
        }
        $this->text->appendElement($document, $info, 'tipoIdentificacionComprador', $data->customerIdentificationTypeCode);
        $this->text->appendElement($document, $info, 'razonSocialComprador', $data->customerName);
        $this->text->appendElement($document, $info, 'identificacionComprador', $data->customerIdentification);
        if (filled($data->customerAddress)) {
            $this->text->appendElement($document, $info, 'direccionComprador', $data->customerAddress);
        }
        $this->text->appendElement($document, $info, 'totalSinImpuestos', $this->decimals->money($data->totalWithoutTaxes));
        $this->text->appendElement($document, $info, 'totalDescuento', $this->decimals->money($data->totalDiscount));

        $totals = $document->createElement('totalConImpuestos');
        $info->appendChild($totals);
        foreach ($data->taxes as $tax) {
            $totalTax = $document->createElement('totalImpuesto');
            $totals->appendChild($totalTax);
            $this->text->appendElement($document, $totalTax, 'codigo', $tax->taxCode);
            $this->text->appendElement($document, $totalTax, 'codigoPorcentaje', $tax->percentageCode);
            $this->text->appendElement($document, $totalTax, 'baseImponible', $this->decimals->taxBase($tax->taxableBase));
            $this->text->appendElement($document, $totalTax, 'tarifa', $this->decimals->percentage($tax->percentage));
            $this->text->appendElement($document, $totalTax, 'valor', $this->decimals->taxAmount($tax->taxAmount));
        }

        $this->text->appendElement($document, $info, 'propina', $this->decimals->money($data->tip));
        $this->text->appendElement($document, $info, 'importeTotal', $this->decimals->money($data->total));
        $this->text->appendElement($document, $info, 'moneda', $data->currency);

        $payments = $document->createElement('pagos');
        $info->appendChild($payments);
        $this->paymentsBuilder->append($document, $payments, $data);
    }
}
