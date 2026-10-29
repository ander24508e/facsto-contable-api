<?php

namespace App\Domains\Sales\Services;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Services\FiscalRuleResolver;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * The single fiscal math of a sale. The draft writer saves what this returns and POST /sales/quote
 * shows it while the sale is being typed, so the web, Taller and Lavadora never differ by a cent
 * from the XML.
 */
final class SaleQuoteService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleDraftCalculator $calculator,
        private readonly FiscalRuleResolver $rules,
    ) {}

    /**
     * @param  array{customerPublicId?: ?string, lines?: list<array<string, mixed>>, paymentMethods?: list<array<string, mixed>>, tipAmount?: ?string}  $data
     * @return array{customer: ?Customer, calculation: array<string, mixed>, payments: list<array<string, mixed>>}
     */
    public function prepare(array $data): array
    {
        $company = $this->context->company();
        $customer = filled($data['customerPublicId'] ?? null)
            ? $company->customers()->where('public_id', $data['customerPublicId'])->where('is_active', true)->first()
            : null;

        if (filled($data['customerPublicId'] ?? null) && ! $customer) {
            throw new ApiException(ApiErrorCode::CUSTOMER_NOT_FOUND, 'Selecciona un cliente activo de la empresa actual.', 422);
        }

        $lines = $data['lines'] ?? [];
        $itemIds = array_values(array_unique(array_column($lines, 'itemPublicId')));
        $items = $company->items()
            ->whereIn('public_id', $itemIds)
            ->where('is_active', true)
            ->with(['taxes.definition', 'priceTiers'])
            ->get()
            ->keyBy('public_id');

        if ($items->count() !== count($itemIds)) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'Uno o más artículos no están activos en la empresa actual.', 422);
        }

        $calculation = $this->calculator->calculate($lines, $items, (string) ($data['tipAmount'] ?? '0.00'));
        $this->assertTipWithinLimit($calculation);

        $payments = [];
        foreach ($data['paymentMethods'] ?? [] as $methodData) {
            $method = FiscalPaymentMethod::query()
                ->where('public_id', $methodData['fiscalPaymentMethodPublicId'])
                ->where('is_active', true)
                ->whereDate('valid_from', '<=', today())
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                ->first();
            if (! $method) {
                throw new ApiException(ApiErrorCode::FISCAL_PAYMENT_METHOD_NOT_FOUND, 'Uno de los métodos fiscales de pago no está vigente.', 422);
            }
            $payments[] = [
                'fiscal_payment_method_id' => $method->id,
                'fiscal_payment_method_public_id' => $method->public_id,
                'method_code' => $method->code,
                'method_name' => $method->name,
                'amount' => bcadd((string) $methodData['amount'], '0', 2),
                'reference' => $methodData['reference'] ?? null,
                'term' => $methodData['term'] ?? null,
                'time_unit' => $methodData['timeUnit'] ?? null,
            ];
        }

        return ['customer' => $customer, 'calculation' => $calculation, 'payments' => $payments];
    }

    /** POST /sales/quote: totals, taxes per rate, payment balance and what is still missing to invoice. */
    public function quote(array $data): array
    {
        ['customer' => $customer, 'calculation' => $calculation, 'payments' => $payments] = $this->prepare($data);

        $summary = [];
        foreach ($calculation['details'] as $line) {
            foreach ($line['taxes'] as $tax) {
                $key = $tax['sri_code'].'|'.$tax['percentage_code'];
                $summary[$key] ??= [
                    'taxType' => $tax['tax_type'], 'sriCode' => $tax['sri_code'], 'percentageCode' => $tax['percentage_code'],
                    'name' => $tax['tax_name'], 'percentage' => $tax['percentage'], 'taxableBase' => '0.00', 'taxAmount' => '0.00',
                ];
                $summary[$key]['taxableBase'] = bcadd($summary[$key]['taxableBase'], $tax['taxable_base'], 2);
                $summary[$key]['taxAmount'] = bcadd($summary[$key]['taxAmount'], $tax['tax_amount'], 2);
            }
        }

        $paymentTotal = array_reduce($payments, fn (string $carry, array $payment): string => bcadd($carry, $payment['amount'], 2), '0.00');
        $pending = [];
        if (! $customer) {
            $pending[] = 'customer';
        }
        if ($calculation['details'] === []) {
            $pending[] = 'lines';
        }
        if ($payments === []) {
            $pending[] = 'payment_methods';
        } elseif (bccomp($paymentTotal, $calculation['total'], 2) !== 0) {
            $pending[] = 'payment_total';
        }
        if ($customer?->identification_type === IdentificationType::FINAL_CONSUMER
            && bccomp($calculation['total'], $this->rules->decimalAt(FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, today()), 2) > 0) {
            $pending[] = 'final_consumer_limit';
        }

        return [
            'lines' => array_map(fn (array $line): array => [
                'itemPublicId' => $line['item']->public_id,
                'quantity' => $line['quantity'],
                'unitPrice' => $line['unit_price'],
                'discountAmount' => $line['discount_amount'],
                'subtotal' => $line['subtotal'],
                'taxableBase' => $line['taxable_base'],
                'taxTotal' => $line['tax_total'],
                'total' => $line['total'],
            ], $calculation['details']),
            'subtotal' => $calculation['subtotal'],
            'discountTotal' => $calculation['discountTotal'],
            'taxableBase' => bcsub($calculation['subtotal'], $calculation['discountTotal'], 2),
            'taxTotal' => $calculation['taxTotal'],
            'tipAmount' => $calculation['tipAmount'],
            'total' => $calculation['total'],
            'taxSummary' => array_values($summary),
            'paymentTotal' => $paymentTotal,
            'paymentDifference' => bcsub($calculation['total'], $paymentTotal, 2),
            'pending' => $pending,
        ];
    }

    /** Tabla 21: la propina no supera el porcentaje normativo del subtotal (antes de impuestos). */
    private function assertTipWithinLimit(array $calculation): void
    {
        if (bccomp($calculation['tipAmount'], '0', 2) === 0) {
            return;
        }
        $base = bcsub($calculation['subtotal'], $calculation['discountTotal'], 2);
        $percentage = $this->rules->decimalAt(FiscalRuleCode::MAX_TIP_PERCENTAGE, today());
        $maximum = bcdiv(bcmul($base, $percentage, 8), '100', 2);
        if (bccomp($calculation['tipAmount'], $maximum, 2) > 0) {
            throw new ApiException(ApiErrorCode::TIP_MAX_PERCENTAGE_EXCEEDED, "La propina no puede superar el {$percentage} % del subtotal ({$maximum}).", 422, [
                'maximumTip' => $maximum, 'maxPercentage' => $percentage,
            ]);
        }
    }
}
