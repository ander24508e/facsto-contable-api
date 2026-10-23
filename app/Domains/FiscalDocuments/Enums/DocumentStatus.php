<?php

namespace App\Domains\FiscalDocuments\Enums;

enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case NUMBERED = 'NUMBERED';
    case XML_BUILT = 'XML_BUILT';
    case SIGNED = 'SIGNED';
    case QUEUED = 'QUEUED';
    case RECEIVED = 'RECEIVED';
    case RETURNED = 'RETURNED';
    case PENDING_AUTHORIZATION = 'PENDING_AUTHORIZATION';
    case AUTHORIZED = 'AUTHORIZED';
    case NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    case FAILED = 'FAILED';
}
