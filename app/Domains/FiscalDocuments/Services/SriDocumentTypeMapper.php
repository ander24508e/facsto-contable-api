<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\FiscalDocuments\Enums\DocumentType;
use InvalidArgumentException;

final class SriDocumentTypeMapper
{
    public function code(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::INVOICE => '01',
            default => throw new InvalidArgumentException('Tipo de comprobante no soportado.'),
        };
    }
}
