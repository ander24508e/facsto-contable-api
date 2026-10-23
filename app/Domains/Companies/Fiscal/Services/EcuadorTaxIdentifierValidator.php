<?php

namespace App\Domains\Companies\Fiscal\Services;

final class EcuadorTaxIdentifierValidator
{
    public function isValidRuc(string $ruc): bool
    {
        if (! preg_match('/^\d{13}$/', $ruc)) {
            return false;
        }

        $province = (int) substr($ruc, 0, 2);
        if (($province < 1 || $province > 24) && $province !== 30) {
            return false;
        }

        $type = (int) $ruc[2];
        $establishment = substr($ruc, 10, 3);
        if ($establishment === '000') {
            return false;
        }

        return match (true) {
            $type <= 5 => $this->validNaturalPersonRuc($ruc),
            $type === 6 => $this->validEntityRuc(substr($ruc, 0, 8), (int) $ruc[8], [3, 2, 7, 6, 5, 4, 3, 2]),
            $type === 9 => $this->validEntityRuc(substr($ruc, 0, 9), (int) $ruc[9], [4, 3, 2, 7, 6, 5, 4, 3, 2]),
            default => false,
        };
    }

    private function validNaturalPersonRuc(string $ruc): bool
    {
        $cedula = substr($ruc, 0, 10);
        $sum = 0;

        for ($index = 0; $index < 9; $index++) {
            $digit = (int) $cedula[$index] * ($index % 2 === 0 ? 2 : 1);
            $sum += $digit > 9 ? $digit - 9 : $digit;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) $cedula[9];
    }

    /** @param list<int> $weights */
    private function validEntityRuc(string $base, int $providedCheckDigit, array $weights): bool
    {
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += (int) $base[$index] * $weight;
        }

        $checkDigit = 11 - ($sum % 11);
        if ($checkDigit === 11) {
            $checkDigit = 0;
        }

        return $checkDigit < 10 && $checkDigit === $providedCheckDigit;
    }
}
