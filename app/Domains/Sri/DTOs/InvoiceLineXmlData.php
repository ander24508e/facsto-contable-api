<?php

namespace App\Domains\Sri\DTOs;

final readonly class InvoiceLineXmlData
{
    /** @param list<InvoiceTaxXmlData> $taxes */
    public function __construct(
        public string $itemCode,
        public ?string $auxiliaryCode,
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $discount,
        public string $priceTotalWithoutTax,
        public array $taxes,
    ) {}
}
