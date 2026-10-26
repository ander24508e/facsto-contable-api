<?php

namespace App\Domains\Taxes\Enums;

/** ICE (tabla 18) mezcla tarifas ad valorem y específicas; el IVA siempre es porcentual. */
enum TaxCalculationType: string
{
    case PERCENTAGE = 'PERCENTAGE';
    case SPECIFIC = 'SPECIFIC';
    case MIXED = 'MIXED';
}
