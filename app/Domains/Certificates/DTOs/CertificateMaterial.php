<?php

namespace App\Domains\Certificates\DTOs;

/**
 * Signing material that only lives while one signature is made: a temporary PKCS#12 file and its
 * password, decrypted in memory. It is never serialized, logged or persisted.
 */
final readonly class CertificateMaterial
{
    public function __construct(
        public string $pkcs12Path,
        #[\SensitiveParameter] public string $password,
        public string $certificateDer,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['pkcs12Path' => $this->pkcs12Path, 'password' => '[oculta]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('El material de firma no puede serializarse.');
    }
}
