<?php

namespace App\Domains\Ride\DTOs;

/**
 * Everything printed on the RIDE. It comes only from the document snapshots and the official
 * authorization: later changes to the company, customer, items or taxes never alter it.
 */
final readonly class RideData
{
    /**
     * @param  array<string, string|null>  $issuer
     * @param  array<string, string|null>  $customer
     * @param  list<array<string, string|null>>  $lines
     * @param  list<array{label: string, amount: string}>  $totals
     * @param  list<array<string, string|null>>  $payments
     * @param  array<string, string>  $additionalInformation
     */
    public function __construct(
        public array $issuer,
        public string $documentLabel,
        public string $fullNumber,
        public string $accessKey,
        public string $authorizationNumber,
        public string $authorizedAt,
        public string $environment,
        public string $emissionType,
        public string $issueDate,
        public array $customer,
        public array $lines,
        public array $totals,
        public string $total,
        public array $payments,
        public array $additionalInformation,
        public string $barcodeSvg,
        /** @var array{type: string, number: string, date: string, reason: string}|null credit note: the invoice it modifies */
        public ?array $modifiedDocument = null,
    ) {}
}
