<?php

namespace App\Domains\Sri\Xml\Requirements;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use DOMDocument;
use DOMElement;

interface InvoiceRequirement
{
    public function append(DOMDocument $document, DOMElement $parent, InvoiceXmlData $data): void;
}
