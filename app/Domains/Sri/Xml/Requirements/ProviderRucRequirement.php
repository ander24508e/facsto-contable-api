<?php

namespace App\Domains\Sri\Xml\Requirements;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class ProviderRucRequirement implements InvoiceRequirement
{
    public function __construct(private readonly XmlTextNormalizer $text) {}

    public function append(DOMDocument $document, DOMElement $parent, InvoiceXmlData $data): void
    {
        $element = $document->createElement('campoAdicional');
        $element->setAttribute('nombre', 'RUC Proveedor');
        $element->appendChild($document->createTextNode($this->text->normalize($data->providerRuc)));
        $parent->appendChild($element);
    }
}
