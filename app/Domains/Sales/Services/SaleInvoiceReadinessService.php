<?php

namespace App\Domains\Sales\Services;

use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;

final class SaleInvoiceReadinessService
{
    /** @return array{ready: bool, pending: list<string>} */
    public function summary(Sale $sale): array
    {
        $pending = [];
        if ($sale->status !== SaleStatus::DRAFT) {
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
            if ($line->taxes->contains(fn ($tax): bool => bccomp((string) $tax->percentage, '0', 6) < 0
                || bccomp((string) $tax->taxable_base, '0', 2) < 0
                || bccomp((string) $tax->tax_amount, '0', 2) < 0)) {
                $pending[] = 'taxes';
                break;
            }
        }
        if (bccomp((string) $sale->total, '0', 2) <= 0) {
            $pending[] = 'positive_total';
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
