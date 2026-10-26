<?php

namespace App\Domains\Sri\Xml\Builders;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\Requirements\ProviderRucRequirement;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class AdditionalInformationBuilder
{
    public function __construct(
        private readonly XmlTextNormalizer $text,
        private readonly ProviderRucRequirement $providerRuc,
    ) {}

    public function append(DOMDocument $document, DOMElement $root, InvoiceXmlData $data): void
    {
        $info = $document->createElement('infoAdicional');
        $root->appendChild($info);
        $this->providerRuc->append($document, $info, $data);
        if (filled($data->customerEmail)) {
            $email = $document->createElement('campoAdicional');
            $email->setAttribute('nombre', 'Email');
            $email->appendChild($document->createTextNode($this->text->normalize($data->customerEmail)));
            $info->appendChild($email);
        }
    }
}
