<?php

namespace App\Domains\Sri\Xml\Requirements;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class WithholdingAgentRequirement implements InvoiceRequirement
{
    public function __construct(private readonly XmlTextNormalizer $text) {}

    public function append(DOMDocument $document, DOMElement $parent, InvoiceXmlData $data): void
    {
        if (blank($data->withholdingAgentNumber)) {
            return;
        }

        $number = ltrim($data->withholdingAgentNumber, '0');
        $this->text->appendElement($document, $parent, 'agenteRetencion', $number === '' ? '0' : $number);
    }
}
