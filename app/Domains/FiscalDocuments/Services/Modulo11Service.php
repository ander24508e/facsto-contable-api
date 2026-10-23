<?php

namespace App\Domains\FiscalDocuments\Services;

use InvalidArgumentException;

final class Modulo11Service
{
    public function checkDigit(string $digits): int
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            throw new InvalidArgumentException('El módulo 11 requiere una cadena numérica.');
        }

        $weight = 2;
        $sum = 0;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $sum += (int) $digits[$index] * $weight;
            $weight = $weight === 7 ? 2 : $weight + 1;
        }
        $digit = 11 - ($sum % 11);

        return match ($digit) {
            11 => 0, 10 => 1, default => $digit
        };
    }
}
