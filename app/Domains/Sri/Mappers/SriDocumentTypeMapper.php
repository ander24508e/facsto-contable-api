<?php

namespace App\Domains\Sri\Mappers;

use App\Domains\FiscalDocuments\Enums\DocumentType;
use LogicException;

final class SriDocumentTypeMapper
{
    public function map(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::INVOICE => '01',
            default => throw new LogicException('Unsupported SRI document type.'),
        };
    }
}
