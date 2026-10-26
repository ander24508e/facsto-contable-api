<?php

namespace App\Domains\Sri\Mappers;

use LogicException;

final class SriCurrencyMapper
{
    public function map(string $currency): string
    {
        return match ($currency) {
            'USD' => 'DOLAR',
            default => throw new LogicException('Unsupported SRI currency.'),
        };
    }
}
