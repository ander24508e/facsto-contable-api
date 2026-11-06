<?php

namespace App\Domains\Carwash\Enums;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;

/**
 * Billing state of a work order, read from its FACSTO sale (never copied):
 *  - UNBILLED: no sale yet, or its draft was cancelled.
 *  - DRAFT: the sale draft exists; it is charged at the cash register in Ventas.
 *  - ISSUED: the invoice has its number (not yet authorized).
 *  - AUTHORIZED: the SRI authorized it.
 *  - REJECTED: returned / not authorized, or being corrected in Ventas.
 */
enum BillingStatus: string
{
    case UNBILLED = 'UNBILLED';
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case AUTHORIZED = 'AUTHORIZED';
    case REJECTED = 'REJECTED';

    public static function of(?Sale $sale): self
    {
        return match ($sale?->status) {
            null, SaleStatus::CANCELLED => self::UNBILLED,
            SaleStatus::DRAFT => self::DRAFT,
            SaleStatus::IN_CORRECTION => self::REJECTED,
            SaleStatus::DOCUMENT_PREPARED => match ($sale->electronicDocument?->status) {
                DocumentStatus::AUTHORIZED => self::AUTHORIZED,
                DocumentStatus::RETURNED, DocumentStatus::NOT_AUTHORIZED => self::REJECTED,
                default => self::ISSUED,
            },
        };
    }

    /** An invoice has its number: the vehicle can be delivered. */
    public function isInvoiced(): bool
    {
        return in_array($this, [self::ISSUED, self::AUTHORIZED, self::REJECTED], true);
    }
}
