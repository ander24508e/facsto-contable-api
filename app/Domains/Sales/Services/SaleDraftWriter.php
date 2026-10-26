<?php

namespace App\Domains\Sales\Services;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Services\FiscalRuleResolver;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class SaleDraftWriter
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleDraftCalculator $calculator,
        private readonly AuditRecorder $audit,
        private readonly FiscalRuleResolver $rules,
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
            ->with('taxes.definition')
            ->get()
            ->keyBy('public_id');

        if ($items->count() !== count($itemIds)) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'Uno o más artículos no están activos en la empresa actual.', 422);
        }

        $calculation = $this->calculator->calculate($lines, $items, (string) ($data['tipAmount'] ?? '0.00'));
        $this->assertTipWithinLimit($calculation);
        $paymentSnapshots = [];
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
            $paymentSnapshots[] = [
                'fiscal_payment_method_id' => $method->id,
                'method_code' => $method->code,
                'method_name' => $method->name,
                'amount' => $methodData['amount'],
                'reference' => $methodData['reference'] ?? null,
                'term' => $methodData['term'] ?? null,
                'time_unit' => $methodData['timeUnit'] ?? null,
            ];
        }
        $before = $sale ? $sale->only(['customer_name', 'subtotal', 'discount_total', 'tax_total', 'total', 'status']) : null;

        return DB::transaction(function () use ($sale, $data, $actor, $company, $customer, $calculation, $paymentSnapshots, $before): Sale {
            $attributes = [
                'customer_id' => $customer?->id,
                'created_by' => $sale?->created_by ?? $actor->id,
                'status' => SaleStatus::DRAFT,
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
