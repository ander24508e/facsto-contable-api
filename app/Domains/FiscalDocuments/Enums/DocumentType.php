<?php

namespace App\Domains\FiscalDocuments\Enums;

enum DocumentType: string
{
    case INVOICE = 'INVOICE';
    case CREDIT_NOTE = 'CREDIT_NOTE';

    /** Local copy of the official SRI schema for this document. */
    public function xsdPath(): string
    {
        return (string) config($this === self::CREDIT_NOTE ? 'sri.credit_note.xsd_path' : 'sri.invoice.xsd_path');
    }

    public function label(): string
    {
        return $this === self::CREDIT_NOTE ? 'NOTA DE CRÉDITO' : 'FACTURA';
    }
}
