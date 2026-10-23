<?php

namespace App\Domains\Certificates\Enums;

enum CertificateStatus: string
{
    case VALID = 'VALID';
    case INVALID = 'INVALID';
    case EXPIRED = 'EXPIRED';
}
