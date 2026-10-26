<?php

namespace App\Domains\Sri\Xml;

final class XmlTextNormalizer
{
    public function normalize(string $value): string
    {
        $utf8 = iconv('UTF-8', 'UTF-8//IGNORE', $value);
        $utf8 = $utf8 === false ? '' : $utf8;

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $utf8) ?? '';
    }

    public function appendElement(\DOMDocument $document, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($this->normalize($value)));
        $parent->appendChild($element);

        return $element;
    }
}
