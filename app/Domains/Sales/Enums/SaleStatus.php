<?php

namespace App\Domains\Sales\Enums;

enum SaleStatus: string
{
    case DRAFT = 'DRAFT';
    case DOCUMENT_PREPARED = 'DOCUMENT_PREPARED';
    case CANCELLED = 'CANCELLED';
}
