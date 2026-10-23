<?php

namespace App\Domains\Customers\Enums;

enum IdentificationType: string
{
    case RUC = 'RUC';
    case CEDULA = 'CEDULA';
    case PASSPORT = 'PASSPORT';
    case FINAL_CONSUMER = 'FINAL_CONSUMER';
    case FOREIGN_IDENTIFICATION = 'FOREIGN_IDENTIFICATION';
}
