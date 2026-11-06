<?php

namespace App\Domains\FiscalDocuments\Enums;

enum DocumentType: string
{
    case INVOICE = 'INVOICE';
    case CREDIT_NOTE = 'CREDIT_NOTE';
    case RETENTION = 'RETENTION';

    /** Documents of Ventas: a retention is issued on a purchase, not on a sale. */
    public const SALES = [self::INVOICE, self::CREDIT_NOTE];

    /** Local copy of the official SRI schema for this document. */
    public function xsdPath(): string
    {
        return (string) config(match ($this) {
            self::INVOICE => 'sri.invoice.xsd_path',
            self::CREDIT_NOTE => 'sri.credit_note.xsd_path',
            self::RETENTION => 'sri.retention.xsd_path',
        });
    }

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'FACTURA',
            self::CREDIT_NOTE => 'NOTA DE CRÉDITO',
            self::RETENTION => 'COMPROBANTE DE RETENCIÓN',
        };
    }
}
