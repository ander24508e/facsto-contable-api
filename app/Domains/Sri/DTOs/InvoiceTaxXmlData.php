<?php

namespace App\Domains\Sri\DTOs;

final readonly class InvoiceTaxXmlData
{
    public function __construct(
        public string $taxCode,
        public string $percentageCode,
        public string $percentage,
        public string $taxableBase,
        public string $taxAmount,
    ) {}
}
