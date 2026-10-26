<?php

namespace App\Domains\Taxes\Enums;

/** Ficha técnica SRI, tabla 16. */
enum TaxType: string
{
    case IVA = 'IVA';
    case ICE = 'ICE';
    case IRBPNR = 'IRBPNR';

    public function sriCode(): string
    {
        return match ($this) {
            self::IVA => '2',
            self::ICE => '3',
            self::IRBPNR => '5',
        };
    }
}
