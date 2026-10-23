<?php

namespace App\Domains\Companies\Fiscal\Enums;

enum CompanySetupState: string
{
    case ISSUER_REQUIRED = 'ISSUER_REQUIRED';
    case EMISSION_REQUIRED = 'EMISSION_REQUIRED';
    case CERTIFICATE_REQUIRED = 'CERTIFICATE_REQUIRED';
    case READY = 'READY';
}
