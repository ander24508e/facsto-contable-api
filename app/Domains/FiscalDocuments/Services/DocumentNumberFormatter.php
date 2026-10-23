<?php

namespace App\Domains\FiscalDocuments\Services;

use InvalidArgumentException;

final class DocumentNumberFormatter
{
    public function format(string $establishmentCode, string $emissionPointCode, string $sequential): string
    {
        foreach ([$establishmentCode, $emissionPointCode] as $code) {
            if (! preg_match('/^\d{3}$/', $code)) {
                throw new InvalidArgumentException('Establecimiento y punto de emisión deben tener tres dígitos.');
            }
        }
        if (! preg_match('/^\d{9}$/', $sequential)) {
            throw new InvalidArgumentException('El secuencial debe tener nueve dígitos.');
        }

        return $establishmentCode.'-'.$emissionPointCode.'-'.$sequential;
    }
}
