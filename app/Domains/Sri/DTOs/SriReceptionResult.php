<?php

namespace App\Domains\Sri\DTOs;

use App\Domains\Sri\Enums\SriReceptionStatus;

final readonly class SriReceptionResult
{
    /** @param list<SriMessageData> $messages */
    public function __construct(
        public SriReceptionStatus $status,
        public array $messages,
        public ?string $requestSha256 = null,
        public ?int $httpStatus = null,
    ) {}

    /**
     * DEVUELTA only because the SRI already holds this access key (43 / 70): the right next step
     * is asking for its authorization with the same key, never sending a new number.
     */
    public function alreadyReceived(): bool
    {
        if ($this->status !== SriReceptionStatus::RETURNED) {
            return false;
        }
        $found = false;
        foreach ($this->messages as $message) {
            if ($message->meansAlreadyReceived()) {
                $found = true;
            } elseif ($message->type !== 'ADVERTENCIA') {
                return false;
            }
        }

        return $found;
    }

    public function withTransport(string $requestSha256, int $httpStatus): self
    {
        return new self($this->status, $this->messages, $requestSha256, $httpStatus);
    }
}
