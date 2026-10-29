<?php

namespace App\Domains\Sri\Mappers;

use App\Domains\FiscalDocuments\Enums\DocumentType;

final class SriDocumentTypeMapper
{
    public function map(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::INVOICE => '01',
            DocumentType::CREDIT_NOTE => '04',
        };
    }
}
