<?php

namespace App\Domains\Carwash\Enums;

enum WorkOrderSource: string
{
    case COUNTER = 'COUNTER';
    case RESERVATION = 'RESERVATION';
    case ONLINE = 'ONLINE';
}
