<?php

namespace App\Domains\Sri\Xml;

use DOMDocument;
use RuntimeException;

final class XsdValidator
{
    public function validate(string $xml, string $xsdPath): void
    {
        if (! is_file($xsdPath) || ! is_readable($xsdPath)) {
            throw new RuntimeException('The configured local SRI XSD is unavailable.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            if (! $dom->loadXML($xml, LIBXML_NONET)) {
                throw XmlSchemaValidationException::fromErrors(libxml_get_errors());
            }
            if (! $dom->schemaValidate($xsdPath)) {
                throw XmlSchemaValidationException::fromErrors(libxml_get_errors());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
