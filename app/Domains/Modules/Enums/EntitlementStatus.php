<?php

namespace App\Domains\Modules\Enums;

enum EntitlementStatus: string
{
    case ENABLED = 'ENABLED';
    // Plan vencido o impago: el módulo se consulta pero no se escribe. Nunca se borran datos.
    case READ_ONLY = 'READ_ONLY';
    case DISABLED = 'DISABLED';
}
