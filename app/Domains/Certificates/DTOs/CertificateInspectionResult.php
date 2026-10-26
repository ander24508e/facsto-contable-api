<?php

namespace App\Domains\Certificates\DTOs;

use Carbon\CarbonInterface;

final readonly class CertificateInspectionResult
{
    public function __construct(
        public string $subjectName,
        public ?string $subjectIdentification,
        public ?string $fiscalIdentification,
        public string $issuer,
        public string $serialNumber,
        public CarbonInterface $validFrom,
        public CarbonInterface $validUntil,
        public bool $hasPrivateKey,
    ) {}
}
