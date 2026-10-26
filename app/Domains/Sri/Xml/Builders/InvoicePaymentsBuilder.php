<?php

namespace App\Domains\Sri\Xml\Builders;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\DecimalFormatter;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class InvoicePaymentsBuilder
{
    public function __construct(
        private readonly XmlTextNormalizer $text,
        private readonly DecimalFormatter $decimals,
    ) {}

    public function append(DOMDocument $document, DOMElement $parent, InvoiceXmlData $data): void
    {
        foreach ($data->payments as $payment) {
            $node = $document->createElement('pago');
            $parent->appendChild($node);
            $this->text->appendElement($document, $node, 'formaPago', $payment->methodCode);
            $this->text->appendElement($document, $node, 'total', $this->decimals->money($payment->amount));
            if ($payment->term !== null) {
                $this->text->appendElement($document, $node, 'plazo', $payment->term);
            }
            if ($payment->timeUnit !== null) {
                $this->text->appendElement($document, $node, 'unidadTiempo', $payment->timeUnit);
            }
        }
    }
}
