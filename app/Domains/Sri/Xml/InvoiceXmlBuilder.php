<?php

namespace App\Domains\Sri\Xml;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\Builders\AdditionalInformationBuilder;
use App\Domains\Sri\Xml\Builders\InfoFacturaBuilder;
use App\Domains\Sri\Xml\Builders\InfoTributariaBuilder;
use App\Domains\Sri\Xml\Builders\InvoiceDetailsBuilder;
use DOMDocument;

final class InvoiceXmlBuilder
{
    public function __construct(
        private readonly InfoTributariaBuilder $tributaria,
        private readonly InfoFacturaBuilder $factura,
        private readonly InvoiceDetailsBuilder $details,
        private readonly AdditionalInformationBuilder $additional,
    ) {}

    public function build(InvoiceXmlData $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        $root = $document->createElement('factura');
        $root->setAttribute('id', 'comprobante');
        $root->setAttribute('version', $data->xmlVersion);
        $document->appendChild($root);

        $this->tributaria->append($document, $root, $data);
        $this->factura->append($document, $root, $data);
        $this->details->append($document, $root, $data);
        $this->additional->append($document, $root, $data);

        return $document->saveXML();
    }
}
