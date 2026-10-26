<?php

namespace App\Domains\Sri\DTOs;

final readonly class InvoicePaymentXmlData
{
    public function __construct(
        public string $methodCode,
        public string $amount,
        public ?string $term,
        public ?string $timeUnit,
    ) {}
}
