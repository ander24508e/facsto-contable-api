<?php

namespace App\Domains\Certificates\Enums;

/** Only VALID certificates can become active; every other status keeps metadata for support only. */
enum CertificateStatus: string
{
    case VALID = 'VALID';
    case INVALID = 'INVALID';
    case EXPIRED = 'EXPIRED';
    case NOT_YET_VALID = 'NOT_YET_VALID';
    case RUC_NOT_FOUND = 'RUC_NOT_FOUND';
    case RUC_MISMATCH = 'RUC_MISMATCH';
}
