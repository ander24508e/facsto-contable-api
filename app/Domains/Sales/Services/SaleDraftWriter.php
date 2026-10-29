<?php

namespace App\Domains\Sales\Services;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class SaleDraftWriter
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleQuoteService $quotes,
        private readonly AuditRecorder $audit,
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

        return DB::transaction(function () use ($sale, $data, $actor, $company, $customer, $calculation, $paymentSnapshots, $before): Sale {
            $attributes = [
                'customer_id' => $customer?->id,
                'created_by' => $sale?->created_by ?? $actor->id,
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
                $sale->paymentMethods()->delete();
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

            if ($paymentSnapshots !== []) {
                $sale->paymentMethods()->createMany(array_map(function (array $method) use ($company): array {
                    $method['company_id'] = $company->id;
                    unset($method['fiscal_payment_method_public_id']);

                    return $method;
                }, $paymentSnapshots));
            }

            $this->audit->record($actor, $company, $before === null ? 'sale.draft_created' : 'sale.draft_updated', $sale, $before, [
                'customerPublicId' => $customer?->public_id,
                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'taxTotal' => $sale->tax_total,
                'total' => $sale->total,
                'lineCount' => count($calculation['details']),
            ]);

            return $sale->refresh()->load(['customer', 'creator', 'details.taxes', 'paymentMethods']);
        });
    }
}
