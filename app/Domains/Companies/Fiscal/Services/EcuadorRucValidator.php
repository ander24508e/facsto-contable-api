<?php

namespace App\Domains\Companies\Fiscal\Services;

/** Validates structural RUC rules without claiming to verify SRI registration. */
final class EcuadorRucValidator
{
    public function isStructurallyValid(string $ruc): bool
    {
        if (! preg_match('/^\d{13}$/', $ruc)) {
            return false;
        }

        $province = (int) substr($ruc, 0, 2);
        if (($province < 1 || $province > 24) && $province !== 30) {
            return false;
        }

        $type = (int) $ruc[2];
        if (($type < 0 || $type > 6) && $type !== 9) {
            return false;
        }

        if (substr($ruc, 10, 3) === '000') {
            return false;
        }

        // The SRI does not prescribe an establishment-side check-digit algorithm
        // for every issued public, private, and foreign-person RUC.
        return $type === 6 || $type === 9 || $this->hasValidIdentityCheckDigit($ruc);
    }

    /** Cédula de identidad: province, third digit 0-5 and the módulo 10 check digit. */
    public function isValidCedula(string $cedula): bool
    {
        if (! preg_match('/^\d{10}$/', $cedula)) {
            return false;
        }
        $province = (int) substr($cedula, 0, 2);

        return (($province >= 1 && $province <= 24) || $province === 30)
            && (int) $cedula[2] <= 5
            && $this->hasValidIdentityCheckDigit($cedula);
    }

    /** RUC de persona natural: its cédula followed by an establishment suffix (001). */
    public function isNaturalPersonRuc(string $ruc): bool
    {
        return $this->isStructurallyValid($ruc) && (int) $ruc[2] <= 5;
    }

    private function hasValidIdentityCheckDigit(string $ruc): bool
    {
        $identity = substr($ruc, 0, 10);
        $sum = 0;

        for ($index = 0; $index < 9; $index++) {
            $digit = (int) $identity[$index] * ($index % 2 === 0 ? 2 : 1);
            $sum += $digit > 9 ? $digit - 9 : $digit;
        }

        return (10 - ($sum % 10)) % 10 === (int) $identity[9];
    }
}
