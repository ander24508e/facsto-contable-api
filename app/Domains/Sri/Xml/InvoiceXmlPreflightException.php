<?php

namespace App\Domains\Sri\Xml;

use RuntimeException;

final class InvoiceXmlPreflightException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
