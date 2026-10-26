<?php

namespace App\Domains\Sri\DTOs;

/** One <mensaje> of a SRI answer (identificador, mensaje, informacionAdicional, tipo). */
final readonly class SriMessageData
{
    /** Ficha técnica: 43 = clave de acceso registrada, 70 = clave de acceso en procesamiento. */
    public const ALREADY_RECEIVED_IDENTIFIERS = ['43', '70'];

    public function __construct(
        public ?string $identifier,
        public string $message,
        public ?string $additionalInformation,
        public ?string $type,
    ) {}

    public function meansAlreadyReceived(): bool
    {
        return in_array($this->identifier, self::ALREADY_RECEIVED_IDENTIFIERS, true);
    }
}
