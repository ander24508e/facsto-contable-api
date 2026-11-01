<?php

namespace App\Domains\Purchases\Services;

use App\Domains\Catalog\Actions\CreateItemAction;
use App\Domains\Catalog\Actions\SyncItemTaxesAction;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Credit\Services\InstallmentPlan;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\InventoryLookup;
use App\Domains\Inventory\Services\ItemCodeSuggester;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Models\Supplier;
use App\Domains\Purchases\Actions\EnsureSupplierCustomerAction;
use App\Domains\Taxes\Models\CompanyTax;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a purchase of Inversiones: each line adds its units to the purchase's warehouse (PURCHASE in
 * the kardex) and leaves its unit cost as the product's cost. A line may create a new product right
 * there. Paid on credit, the total is split in installments (accounts payable). A product belongs to one
 * warehouse, so a purchase cannot add units of a product kept in another one.
 */
final class PurchaseWriter
{
    public function __construct(
        private readonly InventoryLookup $inventory,
        private readonly InstallmentPlan $installments,
        private readonly CreateItemAction $createItem,
        private readonly SyncItemTaxesAction $syncTaxes,
        private readonly EnsureSupplierCustomerAction $ensureSupplierCustomer,
        private readonly ItemCodeSuggester $codes,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{supplierPublicId: string, warehousePublicId: string, issueDate?: ?string, supplierDocumentNumber?: ?string, notes?: ?string, fiscalPaymentMethodPublicId: string, downPayment?: ?string, installmentFrequency?: ?string, creditNotes?: ?string, installments?: ?array, lines: list<array<string, mixed>>}  $data
     */
    public function create(Company $company, array $data, User $actor): Purchase
    {
        $supplier = Supplier::query()->where('company_id', $company->id)->where('public_id', $data['supplierPublicId'])->where('is_active', true)->first()
            ?? throw new ApiException(ApiErrorCode::SUPPLIER_NOT_FOUND, 'Selecciona un proveedor activo de la empresa.', 422);
        $warehouse = $this->inventory->activeWarehouse($company, $data['warehousePublicId']);
        $method = FiscalPaymentMethod::query()->valid()->where('public_id', $data['fiscalPaymentMethodPublicId'])->first()
            ?? throw new ApiException(ApiErrorCode::FISCAL_PAYMENT_METHOD_NOT_FOUND, 'La forma de pago no está vigente.', 422);
        $taxes = CompanyTax::query()->where('company_id', $company->id)->with('definition')->get()->keyBy('public_id');

        return DB::transaction(function () use ($company, $data, $actor, $supplier, $warehouse, $method, $taxes): Purchase {
            $this->ensureSupplierCustomer->execute($company, $supplier, $actor);
            $lines = [];
            foreach ($data['lines'] as $row) {
                $item = isset($row['newItem']) ? $this->newItem($company, $row, $actor) : $this->existingItem($company, (string) ($row['itemPublicId'] ?? ''));
                $tax = isset($row['taxPublicId']) ? ($taxes[$row['taxPublicId']] ?? throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'El IVA no está activo en la empresa.', 422)) : null;
                $percentage = (string) ($tax?->definition->fixed_percentage ?? $item->taxes()->with('definition')->get()->first(fn (CompanyTax $itemTax) => $itemTax->definition->tax_type->value === 'IVA')?->definition->fixed_percentage ?? '0');
                $quantity = bcadd((string) $row['quantity'], '0', 6);
                $cost = bcadd((string) $row['unitCost'], '0', 6);
                $subtotal = $this->round(bcmul($quantity, $cost, 8));
                $taxTotal = $this->round(bcdiv(bcmul($subtotal, $percentage, 8), '100', 8));
                $lines[] = compact('item', 'quantity', 'cost', 'percentage', 'subtotal', 'taxTotal');
            }
            $subtotal = array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, $line['subtotal'], 2), '0.00');
            $taxTotal = array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, $line['taxTotal'], 2), '0.00');
            $total = bcadd($subtotal, $taxTotal, 2);
            $terms = $method->is_credit ? $this->installments->terms($data, $total) : null;
            $plan = $terms['installments'] ?? [];

            // Its own sequence: serialized per company so two purchases never share a number.
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $purchase = Purchase::query()->create([
                'company_id' => $company->id,
                'number' => ((int) Purchase::query()->where('company_id', $company->id)->max('number')) + 1,
                'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'created_by' => $actor->id,
                'issue_date' => $data['issueDate'] ?? today()->toDateString(),
                'supplier_document_number' => $data['supplierDocumentNumber'] ?? null,
                'fiscal_payment_method_id' => $method->id, 'payment_method_code' => $method->code, 'payment_method_name' => $method->name,
                'is_credit' => $method->is_credit, 'down_payment' => $terms['down_payment'] ?? '0.00',
                'installment_frequency' => $terms['installment_frequency'] ?? null, 'credit_notes' => $terms['credit_notes'] ?? null,
                'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $item = $line['item'];
                $purchase->lines()->create([
                    'company_id' => $company->id, 'item_id' => $item->id, 'item_code' => $item->internal_code, 'item_name' => $item->name,
                    'quantity' => $line['quantity'], 'unit_cost' => $line['cost'], 'tax_percentage' => $line['percentage'],
                    'subtotal' => $line['subtotal'], 'tax_total' => $line['taxTotal'], 'total' => bcadd($line['subtotal'], $line['taxTotal'], 2),
                ]);
                $this->receive($purchase, $item, $line['quantity'], $actor);
                $item->forceFill(['cost_price' => $line['cost']])->save();
            }
            foreach ($plan as $installment) {
                $purchase->installments()->create([...$installment, 'company_id' => $company->id]);
            }

            $this->audit->record($actor, $company, 'purchase.created', $purchase, null, [
                'number' => $purchase->displayNumber(), 'supplier' => $supplier->identification, 'total' => $total, 'isCredit' => $method->is_credit, 'lineCount' => count($lines),
            ]);

            return $purchase->load(['supplier', 'warehouse', 'creator', 'lines.item', 'installments']);
        });
    }

    private function existingItem(Company $company, string $publicId): Item
    {
        $item = $company->items()->where('public_id', $publicId)->where('is_active', true)->first();
        if (! $item || $item->type !== ItemType::PRODUCT) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'Solo se compran productos activos de la empresa.', 422);
        }

        return $item;
    }

    /** A product that did not exist yet: created with the purchase, which brings its first units. */
    private function newItem(Company $company, array $row, User $actor): Item
    {
        $new = $row['newItem'];
        $item = $this->createItem->execute([
            'type' => ItemType::PRODUCT->value,
            'internal_code' => filled($new['internalCode'] ?? null) ? $new['internalCode'] : $this->codes->suggest($company, (string) $new['name'], null),
            'auxiliary_code' => $new['auxiliaryCode'] ?? null,
            'name' => $new['name'], 'description' => $new['description'] ?? null, 'unit' => $new['unit'] ?? 'UND',
            // A purchase can create a stocked item before its catalog sale price is configured.
            // Use its acquisition unit cost instead of persisting zero.
            'sale_price' => filled($new['salePrice'] ?? null) ? (string) $new['salePrice'] : (string) $row['unitCost'],
            'cost_price' => (string) $row['unitCost'], 'price_includes_tax' => (bool) ($new['priceIncludesTax'] ?? false),
            'category_public_id' => $new['categoryPublicId'] ?? null, 'brand_public_id' => $new['brandPublicId'] ?? null,
            'barcode' => $new['barcode'] ?? null, 'additional_detail' => $new['additionalDetail'] ?? null,
            'price_tiers' => $new['priceTiers'] ?? [],
        ], $actor, stockArrives: true);
        $taxIds = $new['taxPublicIds'] ?? (filled($new['taxPublicId'] ?? null) ? [$new['taxPublicId']] : []);
        if ($taxIds) {
            $this->syncTaxes->execute($item, $taxIds, $actor);
        }

        return $item;
    }

    private function receive(Purchase $purchase, Item $item, string $quantity, User $actor): void
    {
        $stock = ItemStock::query()->where('item_id', $item->id)->lockForUpdate()->first()
            ?? ItemStock::query()->create(['company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $purchase->warehouse_id, 'quantity' => '0']);
        if ($stock->warehouse_id !== $purchase->warehouse_id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_IN_WAREHOUSE, "«{$item->name}» está en otra bodega: cómpralo en su bodega o cámbialo de bodega en Inventario.", 422, ['items' => [$item->name]]);
        }
        $balance = bcadd((string) $stock->quantity, $quantity, 6);
        $stock->forceFill(['quantity' => $balance])->save();
        StockMovement::query()->create([
            'company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $stock->warehouse_id, 'type' => StockMovement::PURCHASE,
            'quantity' => $quantity, 'balance' => $balance, 'purchase_id' => $purchase->id, 'user_id' => $actor->id,
            'note' => 'Compra '.$purchase->displayNumber(),
        ]);
    }

    /** Half up to cents, as money is shown. */
    private function round(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 8) >= 0 ? '0.005' : '-0.005', 2);
    }
}
