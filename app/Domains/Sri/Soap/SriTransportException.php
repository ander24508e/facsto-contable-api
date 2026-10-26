<?php

namespace App\Domains\Sri\Soap;

use RuntimeException;

/** A technical failure talking to the SRI: nothing about the fiscal state of the document is known. */
final class SriTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestSha256 = null,
    ) {
        parent::__construct('SRI transport failure: '.$reasonCode);
    }

    public function withRequest(string $requestSha256): self
    {
        return new self($this->reasonCode, $this->httpStatus, $requestSha256);
    }
}
