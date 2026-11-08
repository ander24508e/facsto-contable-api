<?php

namespace App\Domains\Proformas\Services;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Services\InventoryLookup;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Sales\Services\SaleQuoteService;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Saves a proforma with the same math as a sale (SaleQuoteService only calculates). It writes nothing but
 * the proforma: no stock, no cash, no sale number, no SRI.
 */
final class ProformaWriter
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleQuoteService $quotes,
        private readonly InventoryLookup $inventory,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  array{customerPublicId?: ?string, warehousePublicId?: ?string, issueDate: string, expirationDate: string, notes?: ?string, lines?: list<array<string, mixed>>}  $data */
    public function save(?Proforma $proforma, array $data, User $actor): Proforma
    {
        $company = $this->context->company();
        ['customer' => $customer, 'calculation' => $calculation] = $this->quotes->prepare([...$data, 'paymentMethods' => [], 'tipAmount' => '0.00']);
        $warehouse = filled($data['warehousePublicId'] ?? null) ? $this->inventory->activeWarehouse($company, $data['warehousePublicId']) : null;

        return DB::transaction(function () use ($proforma, $data, $actor, $company, $customer, $calculation, $warehouse): Proforma {
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
            ];
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
            $this->audit->record($actor, $company, $proforma->wasRecentlyCreated ? 'proforma.created' : 'proforma.updated', $proforma, null, [
                'number' => $proforma->displayNumber(), 'total' => $proforma->total, 'lineCount' => count($calculation['details']),
            ]);

            return $proforma->refresh()->load(['customer', 'creator', 'warehouse', 'lines.item']);
        });
    }
}
