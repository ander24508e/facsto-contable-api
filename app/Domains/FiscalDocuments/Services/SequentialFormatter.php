<?php

namespace App\Domains\FiscalDocuments\Services;

use InvalidArgumentException;

final class SequentialFormatter
{
    public function format(int $number): string
    {
        if ($number < 1 || $number > 999999999) {
            throw new InvalidArgumentException('El secuencial debe estar entre 1 y 999999999.');
        }

        return str_pad((string) $number, 9, '0', STR_PAD_LEFT);
    }
}
