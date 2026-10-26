<?php

namespace App\Domains\Sri\Soap;

final readonly class SriTransportResponse
{
    public function __construct(
        public int $httpStatus,
        public string $body,
        public string $requestSha256,
    ) {}
}
