<?php

namespace App\Domains\Proformas\Actions;

use App\Domains\Cash\Services\CashSessionResolver;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleQuoteService;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class ConvertProformaToSaleAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleQuoteService $quotes,
        private readonly CashSessionResolver $cashSessions,
        private readonly AuditRecorder $audit,
        private readonly ProformaFollowUpAction $followUp,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Proforma $proforma, array $data, User $actor): Sale
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($proforma, $data, $actor, $company): Sale {
            $locked = Proforma::query()
                ->where('company_id', $company->id)
                ->whereKey($proforma->id)
                ->lockForUpdate()
                ->with(['customer', 'lines'])
                ->firstOrFail();

            if ($locked->converted_sale_id !== null) {
                $existing = $company->sales()->whereKey($locked->converted_sale_id)->first();
                if ($existing && $existing->status !== SaleStatus::CANCELLED) {
                    return $existing->load(['customer', 'creator', 'cashSession', 'details.taxes', 'paymentMethods', 'installments', 'electronicDocument']);
                }

                throw new ApiException(ApiErrorCode::PROFORMA_CONVERSION_CONFLICT, 'La conversión de esta proforma ya fue iniciada y requiere revisiÃ³n.', 409);
            }

            if (! $locked->isOpen()) {
                throw new ApiException(ApiErrorCode::PROFORMA_NOT_OPEN, 'La proforma ya fue rechazada o convertida.', 409);
            }
            if ($locked->expiration_date->lt(today())) {
                throw new ApiException(ApiErrorCode::PROFORMA_EXPIRED, 'La proforma está vencida. Actualiza su fecha de vencimiento antes de convertirla.', 409);
            }

            if (! empty($data['itemPublicIds'])) {
                $this->followUp->splitForConversion($locked, $data['itemPublicIds'], $actor);
                $locked = $locked->refresh()->load(['customer', 'lines']);
            }

            $session = $this->cashSessions->open($company, $data['cashSessionPublicId']);
            $preparedPayments = $this->quotes->preparePayments($data['paymentMethods']);

            $paymentTotal = array_reduce($preparedPayments, fn (string $total, array $payment): string => bcadd($total, $payment['amount'], 2), '0.00');
            if (bccomp($paymentTotal, (string) $locked->total, 2) !== 0) {
                throw new ApiException(ApiErrorCode::SALE_NOT_READY_FOR_INVOICE, 'Las formas de pago deben cubrir exactamente el total de la proforma.', 422, [
                    'paymentTotal' => $paymentTotal,
                    'proformaTotal' => (string) $locked->total,
                ]);
            }

            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $sale = $company->sales()->create([
                'customer_id' => $locked->customer_id,
                'created_by' => $actor->id,
                'cash_session_id' => $session->id,
                'number' => ((int) $company->sales()->max('number')) + 1,
                'status' => SaleStatus::DRAFT,
                'customer_identification_type' => $locked->customer_identification_type,
                'customer_identification' => $locked->customer_identification,
                'customer_name' => $locked->customer_name,
                'customer_address' => $locked->customer_address,
                'customer_email' => $locked->customer_email,
                'notes' => $locked->notes,
                'subtotal' => $locked->subtotal,
                'discount_total' => $locked->discount_total,
                'tax_total' => $locked->tax_total,
                'tip_amount' => '0.00',
                'total' => $locked->total,
                'currency' => 'USD',
            ]);

            foreach ($locked->lines as $line) {
                $detail = $sale->details()->create([
                    'company_id' => $company->id,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item_code,
                    'item_name' => $line->item_name,
                    'unit' => $line->unit,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_amount' => $line->discount_amount,
                    'subtotal' => $line->subtotal,
                    'taxable_base' => $line->taxable_base,
                    'tax_total' => $line->tax_total,
                    'total' => $line->total,
                ]);
                $detail->taxes()->createMany(array_map(fn (array $tax): array => [
                    'company_id' => $company->id,
                    'company_tax_id' => $tax['company_tax_id'] ?? null,
                    'tax_type' => $tax['tax_type'],
                    'sri_code' => $tax['sri_code'],
                    'percentage_code' => $tax['percentage_code'],
                    'tax_name' => $tax['tax_name'],
                    'percentage' => $tax['percentage'],
                    'taxable_base' => $tax['taxable_base'],
                    'tax_amount' => $tax['tax_amount'],
                ], $line->taxes ?? []));
            }

            $installments = [];
            foreach ($preparedPayments as $payment) {
                $installments = [...$installments, ...$payment['installments']];
                unset($payment['fiscal_payment_method_public_id'], $payment['installments'], $payment['is_transfer']);
                $sale->paymentMethods()->create([...$payment, 'company_id' => $company->id]);
            }
            usort($installments, fn (array $left, array $right): int => strcmp($left['due_date'], $right['due_date']));
            foreach ($installments as $index => $installment) {
                $sale->installments()->create([...$installment, 'number' => $index + 1, 'company_id' => $company->id]);
            }

            $locked->forceFill(['converted_sale_id' => $sale->id])->save();
            $this->audit->record($actor, $company, 'proforma.conversion_started', $locked, null, [
                'number' => $locked->displayNumber(),
                'saleNumber' => $sale->displayNumber(),
                'salePublicId' => $sale->public_id,
            ]);

            return $sale->refresh()->load(['customer', 'creator', 'cashSession', 'details.taxes', 'paymentMethods', 'installments', 'electronicDocument']);
        });
    }
}
