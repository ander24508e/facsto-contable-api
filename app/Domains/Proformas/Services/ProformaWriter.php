<?php

namespace App\Domains\Proformas\Services;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Services\InventoryLookup;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Sales\Services\SaleQuoteService;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Saves a proforma with the same math as a sale (SaleQuoteService only calculates); no cash, no sale number, no SRI.
 * Stock: without payment it only reserves its products; with an advance or on credit it takes them (StockLedger),
 * and an edit takes them again from the new lines.
 */
final class ProformaWriter
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleQuoteService $quotes,
        private readonly InventoryLookup $inventory,
        private readonly AuditRecorder $audit,
        private readonly StockLedger $stock,
    ) {}

    /** @param  array{customerPublicId?: ?string, warehousePublicId?: ?string, issueDate: string, expirationDate: string, notes?: ?string, advanceAmount?: ?string, advancePaymentMethodPublicId?: ?string, onCredit?: bool, lines?: list<array<string, mixed>>}  $data */
    public function save(?Proforma $proforma, array $data, User $actor): Proforma
    {
        $company = $this->context->company();
        ['customer' => $customer, 'calculation' => $calculation] = $this->quotes->prepare([...$data, 'paymentMethods' => [], 'tipAmount' => '0.00']);
        $warehouse = filled($data['warehousePublicId'] ?? null) ? $this->inventory->activeWarehouse($company, $data['warehousePublicId']) : null;
        if ($proforma && ! $proforma->isOpen()) {
            throw new ApiException(ApiErrorCode::PROFORMA_NOT_OPEN, 'La proforma ya fue rechazada o convertida: no se edita.', 409);
        }
        $advance = bcadd((string) ($data['advanceAmount'] ?? '0'), '0', 2);
        $method = bccomp($advance, '0', 2) > 0
            ? (FiscalPaymentMethod::query()->valid()->where('public_id', $data['advancePaymentMethodPublicId'] ?? '')->where('is_credit', false)->first()
                ?? throw new ApiException(ApiErrorCode::FISCAL_PAYMENT_METHOD_NOT_FOUND, 'Elige la forma de pago del anticipo.', 422))
            : null;
        if (bccomp($advance, (string) $calculation['total'], 2) > 0) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El anticipo no puede superar el total.', 422, ['advanceAmount' => ['El anticipo no puede superar el total.']]);
        }

        return DB::transaction(function () use ($proforma, $data, $actor, $company, $customer, $calculation, $warehouse, $advance, $method): Proforma {
            $attributes = [
                'customer_id' => $customer?->id,
                'warehouse_id' => $warehouse?->id ?? $proforma?->warehouse_id,
                'customer_identification_type' => $customer?->identification_type?->value,
                'customer_identification' => $customer?->identification,
                'customer_name' => $customer?->legal_name,
                'customer_address' => $customer?->address,
                'customer_email' => $customer?->email,
                'customer_phone' => $customer?->phone,
                'issue_date' => $data['issueDate'],
                'expiration_date' => $data['expirationDate'],
                'notes' => $data['notes'] ?? null,
                'subtotal' => $calculation['subtotal'],
                'discount_total' => $calculation['discountTotal'],
                'tax_total' => $calculation['taxTotal'],
                'total' => $calculation['total'],
                'advance_amount' => $advance,
                'advance_payment_method_id' => $method?->id,
                'on_credit' => (bool) ($data['onCredit'] ?? false),
            ];
            // What it took before is given back first; the new lines take it again if it still pays.
            if ($proforma?->stock_committed) {
                $this->stock->releaseProforma($proforma, $actor, 'Edición de la proforma');
            }
            if ($proforma) {
                $proforma->forceFill($attributes)->save();
                $proforma->lines()->delete();
            } else {
                // Its own sequence: serialized per company so two proformas never share a number.
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $number = ((int) Proforma::query()->where('company_id', $company->id)->max('number')) + 1;
                $proforma = Proforma::query()->create([...$attributes, 'company_id' => $company->id, 'number' => $number, 'created_by' => $actor->id]);
            }
            foreach ($calculation['details'] as $line) {
                $item = $line['item'];
                $proforma->lines()->create([
                    'company_id' => $company->id, 'item_id' => $item->id, 'item_code' => $item->internal_code, 'item_name' => $item->name,
                    'unit' => $item->unit, 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'], 'subtotal' => $line['subtotal'], 'taxable_base' => $line['taxable_base'],
                    'tax_total' => $line['tax_total'], 'total' => $line['total'], 'taxes' => $line['taxes'],
                ]);
            }
            if ($proforma->refresh()->takesStock()) {
                $this->stock->commitProforma($proforma, $actor);
            }
            $this->audit->record($actor, $company, $proforma->wasRecentlyCreated ? 'proforma.created' : 'proforma.updated', $proforma, null, [
                'number' => $proforma->displayNumber(), 'total' => $proforma->total, 'lineCount' => count($calculation['details']),
            ]);

            return $proforma->refresh()->load(['customer', 'creator', 'warehouse', 'lines.item', 'advancePaymentMethod']);
        });
    }
}
