<?php

namespace App\Domains\Sales\Services;

use App\Domains\Cash\Services\CashSessionResolver;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Domains\Credit\Models\CreditPayment;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class SaleDraftWriter
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleQuoteService $quotes,
        private readonly AuditRecorder $audit,
        private readonly CashSessionResolver $cashSessions,
    ) {}

    public function create(array $data, User $actor): Sale
    {
        return $this->save(null, $data, $actor);
    }

    public function update(Sale $sale, array $data, User $actor): Sale
    {
        return $this->save($sale, $data, $actor);
    }

    private function save(?Sale $sale, array $data, User $actor): Sale
    {
        $company = $this->context->company();
        ['customer' => $customer, 'calculation' => $calculation, 'payments' => $paymentSnapshots] = $this->quotes->prepare($data);
        $before = $sale ? $sale->only(['customer_name', 'subtotal', 'discount_total', 'tax_total', 'total', 'status']) : null;
        // The cash register the sale is charged in; a draft saved without one keeps the one it had.
        $sessionId = filled($data['cashSessionPublicId'] ?? null)
            ? $this->cashSessions->open($company, $data['cashSessionPublicId'])->id
            : $sale?->cash_session_id;

        return DB::transaction(function () use ($sale, $data, $actor, $company, $customer, $calculation, $paymentSnapshots, $before, $sessionId): Sale {
            $attributes = [
                'customer_id' => $customer?->id,
                'created_by' => $sale?->created_by ?? $actor->id,
                'cash_session_id' => $sessionId,
                'status' => $sale?->status ?? SaleStatus::DRAFT,
                'customer_identification_type' => $customer?->identification_type?->value,
                'customer_identification' => $customer?->identification,
                'customer_name' => $customer?->legal_name,
                'customer_address' => $customer?->address,
                'customer_email' => $customer?->email,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $calculation['subtotal'],
                'discount_total' => $calculation['discountTotal'],
                'tax_total' => $calculation['taxTotal'],
                'tip_amount' => $calculation['tipAmount'],
                'total' => $calculation['total'],
                'currency' => 'USD',
            ];

            if ($sale) {
                $sale->forceFill($attributes)->save();
                $sale->details()->delete();
                if (CreditPayment::query()->where('sale_id', $sale->id)->exists()) {
                    throw new ApiException(ApiErrorCode::CREDIT_PAYMENTS_EXIST, 'La venta ya tiene cobros de cuotas registrados; no se puede cambiar.', 409);
                }
                $sale->paymentMethods()->delete();
                $sale->installments()->delete();
            } else {
                // Serialize numbering per company so two drafts never share V-number.
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $attributes['number'] = ((int) $company->sales()->max('number')) + 1;
                $sale = $company->sales()->create($attributes);
            }

            foreach ($calculation['details'] as $line) {
                $item = $line['item'];
                $detail = $sale->details()->create([
                    'company_id' => $company->id,
                    'item_id' => $item->id,
                    'item_code' => $item->internal_code,
                    'auxiliary_code' => $item->auxiliary_code,
                    'item_name' => $item->name,
                    'unit' => $item->unit,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'],
                    'subtotal' => $line['subtotal'],
                    'taxable_base' => $line['taxable_base'],
                    'tax_total' => $line['tax_total'],
                    'total' => $line['total'],
                ]);
                $detail->taxes()->createMany(array_map(
                    fn (array $tax): array => [...$tax, 'company_id' => $company->id],
                    $line['taxes'],
                ));
            }

            $installments = [];
            foreach ($paymentSnapshots as $method) {
                array_push($installments, ...$method['installments']);
                unset($method['fiscal_payment_method_public_id'], $method['installments']);
                $sale->paymentMethods()->create([...$method, 'company_id' => $company->id]);
            }
            // Accounts receivable: the installments of every payment on credit, numbered by date.
            usort($installments, fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']));
            foreach ($installments as $index => $installment) {
                $sale->installments()->create([...$installment, 'number' => $index + 1, 'company_id' => $company->id]);
            }

            $this->audit->record($actor, $company, $before === null ? 'sale.draft_created' : 'sale.draft_updated', $sale, $before, [
                'customerPublicId' => $customer?->public_id,
                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'taxTotal' => $sale->tax_total,
                'total' => $sale->total,
                'lineCount' => count($calculation['details']),
            ]);

            return $sale->refresh()->load(['customer', 'creator', 'cashSession', 'details.taxes', 'paymentMethods', 'installments']);
        });
    }
}
