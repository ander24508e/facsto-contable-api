<?php

namespace App\Domains\Certificates\DTOs;

/** A certificate that passed every check and whose file is already in private storage, not yet active. */
final readonly class AcceptedCertificate
{
    public function __construct(
        public CertificateInspectionResult $inspection,
        public CertificateIdentity $identity,
        public string $publicId,
        public string $storagePath,
        #[\SensitiveParameter] public string $password,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['publicId' => $this->publicId, 'storagePath' => $this->storagePath, 'password' => '[oculta]'];
    }
}
