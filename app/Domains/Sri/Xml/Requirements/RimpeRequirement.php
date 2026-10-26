<?php

namespace App\Domains\Sri\Xml\Requirements;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;
use LogicException;

final class RimpeRequirement implements InvoiceRequirement
{
    public function __construct(private readonly XmlTextNormalizer $text) {}

    public function append(DOMDocument $document, DOMElement $parent, InvoiceXmlData $data): void
    {
        if ($data->rimpeType === null) {
            return;
        }

        $label = match ($data->rimpeType) {
            'ENTREPRENEUR', 'RIMPE' => "CONTRIBUYENTE R\u{00C9}GIMEN RIMPE",
            'POPULAR_BUSINESS', 'POPULAR' => "CONTRIBUYENTE NEGOCIO POPULAR - R\u{00C9}GIMEN RIMPE",
            default => throw new LogicException('Unsupported RIMPE type.'),
        };

        $this->text->appendElement($document, $parent, 'contribuyenteRimpe', $label);
    }
}
