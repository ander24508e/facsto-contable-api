<?php

namespace App\Domains\Sri\Enums;

/** RespuestaRecepcionComprobante.estado */
enum SriReceptionStatus: string
{
    case RECEIVED = 'RECIBIDA';
    case RETURNED = 'DEVUELTA';
}
