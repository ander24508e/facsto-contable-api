<?php

namespace App\Domains\Sri\Signing;

use RuntimeException;

final class XmlSigningException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
