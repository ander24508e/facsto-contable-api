<?php

namespace App\Domains\Sales\Enums;

enum SaleStatus: string
{
    case DRAFT = 'DRAFT';
    // Its invoice was returned or not authorized: the sale is edited again and re-issued with the same number.
    case IN_CORRECTION = 'IN_CORRECTION';
    case DOCUMENT_PREPARED = 'DOCUMENT_PREPARED';
    case CANCELLED = 'CANCELLED';
}
