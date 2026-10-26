<?php

namespace App\Domains\Sales\DTOs;

final readonly class SaleDraftData
{
    /** @param list<array{itemPublicId: string, quantity: string, discountAmount?: string|null}> $lines
     * @param  list<array{fiscalPaymentMethodPublicId: string, amount: string, reference?: string|null, term?: string|null, timeUnit?: string|null}>  $paymentMethods
     */
    public function __construct(
        public ?string $customerPublicId,
        public array $lines = [],
        public array $paymentMethods = [],
        public ?string $notes = null,
        public string $tipAmount = '0.00',
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            $data['customerPublicId'] ?? null,
            $data['lines'] ?? [],
            $data['paymentMethods'] ?? [],
            $data['notes'] ?? null,
            $data['tipAmount'] ?? '0.00',
        );
    }

    public function toArray(): array
    {
        return [
            'customerPublicId' => $this->customerPublicId,
            'lines' => $this->lines,
            'paymentMethods' => $this->paymentMethods,
            'notes' => $this->notes,
            'tipAmount' => $this->tipAmount,
        ];
    }
}
