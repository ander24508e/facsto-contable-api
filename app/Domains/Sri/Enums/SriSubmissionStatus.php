<?php

namespace App\Domains\Sri\Enums;

enum SriSubmissionStatus: string
{
    case IN_PROGRESS = 'IN_PROGRESS';
    case RECEIVED = 'RECEIVED';
    case RETURNED = 'RETURNED';
    case AUTHORIZED = 'AUTHORIZED';
    case NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    case PENDING = 'PENDING';
    /** The transmission gate was closed: nothing left FACSTO. */
    case BLOCKED = 'BLOCKED';
    case TRANSPORT_ERROR = 'TRANSPORT_ERROR';
    case RESPONSE_INVALID = 'RESPONSE_INVALID';
}
