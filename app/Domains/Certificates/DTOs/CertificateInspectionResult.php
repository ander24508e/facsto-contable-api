<?php

namespace App\Domains\Certificates\DTOs;

use Carbon\CarbonInterface;

final readonly class CertificateInspectionResult
{
    /** @param list<array{source: string, value: string, context: string}> $identificationCandidates */
    public function __construct(
        public string $subjectName,
        public ?string $subjectIdentification,
        public array $identificationCandidates,
        public string $issuer,
        public string $serialNumber,
        public CarbonInterface $validFrom,
        public CarbonInterface $validUntil,
        public bool $hasPrivateKey,
    ) {}
}
