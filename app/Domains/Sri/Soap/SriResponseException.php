<?php

namespace App\Domains\Sri\Soap;

use RuntimeException;

/** The SRI answered, but not with a readable SOAP response of the expected operation. */
final class SriResponseException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'RESPONSE_INVALID',
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestSha256 = null,
    ) {
        parent::__construct('SRI response invalid: '.$reasonCode);
    }

    public function withTransport(string $requestSha256, int $httpStatus): self
    {
        return new self($this->reasonCode, $httpStatus, $requestSha256);
    }
}
