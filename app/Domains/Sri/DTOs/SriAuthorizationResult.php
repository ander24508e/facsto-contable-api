<?php

namespace App\Domains\Sri\DTOs;

use App\Domains\Sri\Enums\SriAuthorizationStatus;
use Carbon\CarbonImmutable;

final readonly class SriAuthorizationResult
{
    /**
     * @param  string|null  $authorizationXml  the official <autorizacion> element, exactly as the SRI returned it
     * @param  string|null  $comprobante  the authorized comprobante embedded in that element
     * @param  list<SriMessageData>  $messages
     */
    public function __construct(
        public SriAuthorizationStatus $status,
        public ?string $accessKey,
        public ?string $authorizationNumber,
        public ?CarbonImmutable $authorizedAt,
        public ?string $environment,
        public ?string $authorizationXml,
        public ?string $comprobante,
        public array $messages,
        public ?string $requestSha256 = null,
        public ?int $httpStatus = null,
    ) {}

    public function withTransport(string $requestSha256, int $httpStatus): self
    {
        return new self($this->status, $this->accessKey, $this->authorizationNumber, $this->authorizedAt, $this->environment,
            $this->authorizationXml, $this->comprobante, $this->messages, $requestSha256, $httpStatus);
    }
}
