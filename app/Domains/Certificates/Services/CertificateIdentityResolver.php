<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\DTOs\CertificateIdentity;
use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;

/**
 * Decides whether a certificate belongs to the issuer. Only configured sources count:
 * - a RUC source must carry exactly the company RUC (any taxpayer type);
 * - a cédula source counts only for a persona natural, whose RUC is that cédula + 001.
 * A sociedad never signs with the cédula of its legal representative.
 */
final class CertificateIdentityResolver
{
    public function __construct(private readonly EcuadorRucValidator $validator) {}

    /** @param list<array{source: string, value: string, context: string}> $candidates */
    public function resolve(array $candidates, string $companyRuc): CertificateIdentity
    {
        $rucSources = (array) config('sri.certificate_identification.ruc_sources', []);
        $cedulaSources = (array) config('sri.certificate_identification.cedula_sources', []);
        $byRuc = [];
        $byCedula = [];

        foreach ($candidates as $candidate) {
            $value = $candidate['value'];
            if (in_array($candidate['source'], $rucSources, true) && strlen($value) === 13 && $this->validator->isStructurallyValid($value)) {
                $byRuc[] = [$value, $candidate['source']];
            }
            if (in_array($candidate['source'], $cedulaSources, true) && strlen($value) === 10 && $this->validator->isValidCedula($value)) {
                $byCedula[] = [$value.'001', $candidate['source']];
            }
        }
        // An explicit RUC always wins over a RUC derived from the cédula.
        $found = [...$byRuc, ...$byCedula];

        foreach ($found as [$ruc, $source]) {
            $viaCedula = in_array($source, $cedulaSources, true) && ! in_array($source, $rucSources, true);
            if ($ruc === $companyRuc && (! $viaCedula || $this->validator->isNaturalPersonRuc($companyRuc))) {
                return new CertificateIdentity(true, $ruc, $source);
            }
        }

        return new CertificateIdentity(false, $found[0][0] ?? null, $found[0][1] ?? null);
    }
}
