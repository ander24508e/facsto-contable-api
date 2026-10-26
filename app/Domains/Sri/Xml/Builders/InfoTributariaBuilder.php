<?php

namespace App\Domains\Sri\Xml\Builders;

use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\Requirements\RimpeRequirement;
use App\Domains\Sri\Xml\Requirements\WithholdingAgentRequirement;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use DOMDocument;
use DOMElement;

final class InfoTributariaBuilder
{
    public function __construct(
        private readonly XmlTextNormalizer $text,
        private readonly WithholdingAgentRequirement $withholdingAgent,
        private readonly RimpeRequirement $rimpe,
    ) {}

    public function append(DOMDocument $document, DOMElement $root, InvoiceXmlData $data): void
    {
        $info = $document->createElement('infoTributaria');
        $root->appendChild($info);

        $this->text->appendElement($document, $info, 'ambiente', $data->environmentCode);
        $this->text->appendElement($document, $info, 'tipoEmision', $data->emissionTypeCode);
        $this->text->appendElement($document, $info, 'razonSocial', $data->issuerLegalName);
        if (filled($data->issuerCommercialName)) {
            $this->text->appendElement($document, $info, 'nombreComercial', $data->issuerCommercialName);
        }
        $this->text->appendElement($document, $info, 'ruc', $data->issuerRuc);
        $this->text->appendElement($document, $info, 'claveAcceso', $data->accessKey);
        $this->text->appendElement($document, $info, 'codDoc', $data->documentTypeCode);
        $this->text->appendElement($document, $info, 'estab', $data->establishmentCode);
        $this->text->appendElement($document, $info, 'ptoEmi', $data->emissionPointCode);
        $this->text->appendElement($document, $info, 'secuencial', $data->sequential);
        $this->text->appendElement($document, $info, 'dirMatriz', $data->issuerMainAddress);

        $this->withholdingAgent->append($document, $info, $data);
        $this->rimpe->append($document, $info, $data);
    }
}
