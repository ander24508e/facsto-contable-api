<?php

namespace App\Domains\Sales\Services;

use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Services\FiscalRuleResolver;

final class SaleInvoiceReadinessService
{
    public function __construct(private readonly FiscalRuleResolver $rules) {}

    /** @return array{ready: bool, pending: list<string>} */
    public function summary(Sale $sale): array
    {
        $pending = [];
        if (! in_array($sale->status, [SaleStatus::DRAFT, SaleStatus::IN_CORRECTION], true)) {
            $pending[] = 'sale_draft';
        }
        if (! $sale->customer_id) {
            $pending[] = 'customer';
        }

        $sale->loadMissing(['details.taxes', 'paymentMethods']);
        if ($sale->details->isEmpty()) {
            $pending[] = 'lines';
        }
        foreach ($sale->details as $line) {
            if (bccomp((string) $line->quantity, '0', 6) <= 0 || bccomp((string) $line->unit_price, '0', 6) < 0) {
                $pending[] = 'line_values';
                break;
            }
            if ($line->taxes->where('tax_type', 'IVA')->count() !== 1
                || $line->taxes->contains(fn ($tax): bool => bccomp((string) $tax->percentage, '0', 6) < 0
                || bccomp((string) $tax->taxable_base, '0', 2) < 0
                || bccomp((string) $tax->tax_amount, '0', 2) < 0)) {
                $pending[] = 'taxes';
                break;
            }
        }
        if (bccomp((string) $sale->total, '0', 2) <= 0) {
            $pending[] = 'positive_total';
        }
        // Ficha técnica 9.10: above the limit the buyer must be identified.
        // Rules are resolved on the fiscal date of the sale; a draft uses today.
        $fiscalDate = $sale->issue_date ?? today();
        if ($sale->customer_identification_type === IdentificationType::FINAL_CONSUMER->value
            && bccomp((string) $sale->total, $this->rules->decimalAt(FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, $fiscalDate), 2) > 0) {
            $pending[] = 'final_consumer_limit';
        }
        if (bccomp((string) $sale->tip_amount, '0', 2) > 0) {
            $base = bcsub((string) $sale->subtotal, (string) $sale->discount_total, 2);
            $maximum = bcdiv(bcmul($base, $this->rules->decimalAt(FiscalRuleCode::MAX_TIP_PERCENTAGE, $fiscalDate), 8), '100', 2);
            if (bccomp((string) $sale->tip_amount, $maximum, 2) > 0) {
                $pending[] = 'tip_limit';
            }
        }

        $payments = $sale->paymentMethods;
        if ($payments->isEmpty()) {
            $pending[] = 'payment_methods';
        } else {
            $paymentTotal = '0.00';
            foreach ($payments as $payment) {
                if (! $payment->fiscal_payment_method_id || blank($payment->method_code) || blank($payment->method_name)
                    || bccomp((string) $payment->amount, '0', 2) <= 0) {
                    $pending[] = 'payment_methods';
                    break;
                }
                $paymentTotal = bcadd($paymentTotal, (string) $payment->amount, 2);
            }
            if (! in_array('payment_methods', $pending, true) && bccomp($paymentTotal, (string) $sale->total, 2) !== 0) {
                $pending[] = 'payment_total';
            }
        }

        return ['ready' => $pending === [], 'pending' => array_values(array_unique($pending))];
    }
}
