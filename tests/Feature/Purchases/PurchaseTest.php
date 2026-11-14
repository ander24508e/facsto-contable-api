<?php

namespace Tests\Feature\Purchases;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

/** Inversiones: a purchase adds stock (PURCHASE), can create a product and is paid in installments. */
final class PurchaseTest extends SalesTestCase
{
    public function test_a_purchase_adds_units_creates_products_and_is_paid_in_installments(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $stock = $this->productStock($company, '5');
        $iva = $this->companyIva($company, '4')->public_id;
        $water = $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $stock])
            ->assertCreated()->json('data.publicId');
        $supplier = $this->postJson('/api/v1/suppliers', ['identification' => '1790012345001', 'legalName' => 'Distribuidora Andina'])
            ->assertCreated()->assertJsonPath('data.legalName', 'DISTRIBUIDORA ANDINA')->json('data.publicId');
        $this->postJson('/api/v1/suppliers', ['identification' => '1790012345001', 'legalName' => 'Otra'])->assertConflict()->assertJsonPath('error.code', 'SUPPLIER_ALREADY_EXISTS');
        $credit = FiscalPaymentMethod::query()->where('is_credit', true)->value('public_id');
        $month = now((string) config('sri.timezone'))->addMonth()->toDateString();
        $body = [
            'supplierPublicId' => $supplier, 'warehousePublicId' => $stock['warehousePublicId'], 'fiscalPaymentMethodPublicId' => $credit,
            'supplierDocumentNumber' => '001-001-000000123', 'downPayment' => '2.12', 'installmentFrequency' => 'MONTHLY',
            'lines' => [
                ['itemPublicId' => $water, 'quantity' => '10', 'unitCost' => '0.40', 'taxPublicId' => $iva],
                ['newItem' => ['name' => 'Jugo de naranja', 'salePrice' => '1.50', 'taxPublicId' => $iva], 'quantity' => '6', 'unitCost' => '0.80'],
            ],
        ];

        // 4.00 + 4.80 = 8.80 plus IVA 15% (0.60 + 0.72) = 10.12: 2.12 down and 8.00 in two installments.
        $this->postJson('/api/v1/purchases', [...$body, 'installments' => [['dueDate' => $month, 'amount' => '5.00']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INSTALLMENTS_INVALID');
        $this->assertSame(0, Item::query()->where('name', 'Jugo de naranja')->count());

        $purchase = $this->postJson('/api/v1/purchases', [...$body, 'installments' => [['dueDate' => $month, 'amount' => '4.00'], ['dueDate' => now()->addMonths(2)->toDateString(), 'amount' => '4.00']]])
            ->assertCreated()
            ->assertJsonPath('data.number', 'COM-000001')
            ->assertJsonPath('data.subtotal', '8.80')
            ->assertJsonPath('data.taxTotal', '1.32')
            ->assertJsonPath('data.total', '10.12')
            ->assertJsonPath('data.paymentMethod.isCredit', true)
            ->assertJsonPath('data.downPayment', '2.12')
            ->assertJsonPath('data.balance', '8.00')
            ->assertJsonCount(2, 'data.installments')
            ->json('data');

        $juice = Item::query()->where('name', 'Jugo de naranja')->firstOrFail();
        $this->assertSame('15.000000', (string) ItemStock::query()->where('item_id', Item::query()->where('internal_code', 'AGUA')->value('id'))->firstOrFail()->quantity);
        $this->assertSame('6.000000', (string) ItemStock::query()->where('item_id', $juice->id)->firstOrFail()->quantity);
        $this->assertSame('0.400000', (string) Item::query()->where('internal_code', 'AGUA')->firstOrFail()->cost_price);
        $this->assertSame(2, StockMovement::query()->where('type', StockMovement::PURCHASE)->where('note', 'Compra COM-000001')->count());
        $this->getJson("/api/v1/items/{$juice->public_id}/stock-movements")->assertOk()->assertJsonPath('data.0.type', 'PURCHASE');

        $this->getJson('/api/v1/purchases?search=andina')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.supplier.identification', '1790012345001');
        $this->getJson("/api/v1/purchases/{$purchase['publicId']}")->assertOk()->assertJsonPath('data.lines.1.itemName', 'Jugo de naranja');

        // Cuentas por pagar: paid to the supplier from the oldest installment on.
        $this->getJson('/api/v1/payables?status=PENDING')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.balance', '8.00');
        $this->postJson("/api/v1/payables/{$purchase['publicId']}/payments", ['amount' => '9.00'])->assertUnprocessable()->assertJsonPath('error.code', 'CREDIT_PAYMENT_EXCEEDS_BALANCE');
        $this->postJson("/api/v1/payables/{$purchase['publicId']}/payments", ['amount' => '5.00'])->assertCreated()
            ->assertJsonPath('data.installments.0.balance', '0.00')->assertJsonPath('data.installments.1.paidAmount', '1.00')
            ->assertJsonPath('data.balance', '3.00')->assertJsonCount(1, 'data.payments');

        $other = User::factory()->create();
        $this->createCompany($other, '1788888888001');
        $this->actingAs($other)->getJson("/api/v1/purchases/{$purchase['publicId']}")->assertNotFound();
        $this->getJson("/api/v1/payables/{$purchase['publicId']}")->assertNotFound();
    }

    public function test_a_purchase_adds_the_units_to_its_own_warehouse(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $stock = $this->productStock($company, '5');
        $water = $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $stock])
            ->assertCreated()->json('data.publicId');
        $other = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $company->establishments()->value('id'), 'name' => 'Bodega 2']);
        $supplier = $this->postJson('/api/v1/suppliers', ['identification' => '1790012345001', 'legalName' => 'Andina'])->json('data.publicId');
        $cash = FiscalPaymentMethod::query()->where('is_credit', false)->where('code', '01')->value('public_id');

        $this->postJson('/api/v1/purchases', [
            'supplierPublicId' => $supplier, 'warehousePublicId' => $other->public_id, 'fiscalPaymentMethodPublicId' => $cash,
            'lines' => [['itemPublicId' => $water, 'quantity' => '1', 'unitCost' => '0.40']],
        ])->assertCreated();
        // A product may have units in several warehouses: the purchase opens Bodega 2 and the main one keeps its 5.
        $itemId = Item::query()->where('public_id', $water)->value('id');
        $this->assertSame('1.000000', ItemStock::query()->where('item_id', $itemId)->where('warehouse_id', $other->id)->value('quantity'));
        $this->assertSame('5.000000', ItemStock::query()->where('item_id', $itemId)->where('warehouse_id', '<>', $other->id)->value('quantity'));
    }
}
