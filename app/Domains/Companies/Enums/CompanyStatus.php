<?php

namespace App\Domains\Companies\Enums;

enum CompanyStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
