<?php

namespace App\Domains\FiscalDocuments\Services;

final class NumericCodeGenerator
{
    public function generate(): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
