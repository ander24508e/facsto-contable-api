<?php

namespace App\Domains\Sri\Enums;

/** autorizacion.estado: AUTORIZADO (AUT), NO AUTORIZADO (NAT), EN PROCESO (PPR) or no answer yet. */
enum SriAuthorizationStatus: string
{
    case AUTHORIZED = 'AUTHORIZED';
    case NOT_AUTHORIZED = 'NOT_AUTHORIZED';
    case PENDING = 'PENDING';

    public static function fromSri(string $estado): self
    {
        return match (mb_strtoupper(trim($estado))) {
            'AUTORIZADO', 'AUT' => self::AUTHORIZED,
            'NO AUTORIZADO', 'NAT', 'RECHAZADA', 'RECHAZADO' => self::NOT_AUTHORIZED,
            default => self::PENDING,
        };
    }
}
