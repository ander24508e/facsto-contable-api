<?php

namespace App\Domains\Certificates\DTOs;

/** Which RUC a certificate vouches for, and from which recognized source. */
final readonly class CertificateIdentity
{
    public function __construct(
        public bool $matchesCompany,
        public ?string $ruc,
        public ?string $source,
    ) {}

    public function found(): bool
    {
        return $this->ruc !== null;
    }
}
