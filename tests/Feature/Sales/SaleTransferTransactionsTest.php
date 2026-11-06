<?php

namespace Tests\Feature\Sales;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Sales\Models\Sale;
use App\Models\User;

/** TRANSFERENCIA (code 20, sales only, with its reference) and Ventas → Transacciones. */
final class SaleTransferTransactionsTest extends InvoiceFeatureTestCase
{
    public function test_a_transfer_needs_its_reference_and_is_listed_in_transactions(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company, withPayment: false);

        $this->actingAs($user)->patchJson("/api/v1/sales/{$sale->public_id}", $this->body($sale, null))->assertOk();
        $this->getJson("/api/v1/sales/{$sale->public_id}")->assertOk()->assertJsonPath('meta.invoiceReadiness.pending', ['payment_reference']);
        $this->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertUnprocessable()
            ->assertJsonPath('error.code', 'SALE_NOT_READY_FOR_INVOICE')
            ->assertJsonPath('error.details.pending', ['payment_reference']);

        $this->patchJson("/api/v1/sales/{$sale->public_id}", $this->body($sale, 'BP-778812'))->assertOk()
            ->assertJsonPath('data.paymentMethods.0.methodCode', '20')
            ->assertJsonPath('data.paymentMethods.0.methodName', 'TRANSFERENCIA')
            ->assertJsonPath('data.paymentMethods.0.reference', 'BP-778812');
        $this->postJson("/api/v1/sales/{$sale->public_id}/issue");
        $this->assertNotNull($sale->refresh()->electronicDocument);

        $this->getJson('/api/v1/sales/transactions?search=bp-7788')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'BP-778812')
            ->assertJsonPath('data.0.isTransfer', true)
            ->assertJsonPath('data.0.amount', '10.00')
            ->assertJsonPath('data.0.sale.publicId', $sale->public_id)
            ->assertJsonPath('data.0.invoice.fullNumber', '001-001-000000001')
            ->assertJsonPath('data.0.customer.name', 'Cliente de prueba');
        $this->getJson('/api/v1/sales/transactions?method='.$this->paymentMethod()->public_id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sales/transactions?status=problems&search=BP')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_drafts_and_other_companies_never_appear_and_the_total_counts_authorized_invoices_only(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->makeSale($user, $company);

        $this->actingAs($user)->getJson('/api/v1/sales/transactions')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.collectedTotal', '0.00');

        $other = User::factory()->create();
        $otherCompany = $this->readyCompany($other, '1799999998001', '1790012345001');
        $otherSale = $this->makeSale($other, $otherCompany);
        $this->actingAs($other)->postJson("/api/v1/sales/{$otherSale->public_id}/issue");
        $otherSale->refresh()->electronicDocument?->forceFill(['status' => 'AUTHORIZED'])->save();

        $this->actingAs($other)->getJson('/api/v1/sales/transactions')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.collectedTotal', '10.00');
        $this->actingAs($user)->getJson('/api/v1/sales/transactions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_purchase_never_takes_a_transfer(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $stock = $this->productStock($company, '5');
        $supplier = $this->actingAs($user)->postJson('/api/v1/suppliers', ['identification' => '1790012345001', 'legalName' => 'Distribuidora Andina'])
            ->assertCreated()->json('data.publicId');

        $this->postJson('/api/v1/purchases', [
            'supplierPublicId' => $supplier, 'warehousePublicId' => $stock['warehousePublicId'], 'fiscalPaymentMethodPublicId' => $this->transfer()->public_id,
            'lines' => [['newItem' => ['name' => 'Jabón', 'salePrice' => '2.00', 'taxPublicId' => $this->companyIva($company, '4')->public_id], 'quantity' => '1', 'unitCost' => '1.00']],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'FISCAL_PAYMENT_METHOD_NOT_FOUND');
        $this->assertDatabaseCount('purchases', 0);
    }

    private function transfer(): FiscalPaymentMethod
    {
        return FiscalPaymentMethod::query()->where('is_transfer', true)->firstOrFail();
    }

    private function body(Sale $sale, ?string $reference): array
    {
        $sale->load(['customer', 'details.item']);

        return [
            'customerPublicId' => $sale->customer->public_id,
            'lines' => $sale->details->map(fn ($line): array => ['itemPublicId' => $line->item->public_id, 'quantity' => (string) $line->quantity])->all(),
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->transfer()->public_id, 'amount' => '10.00', 'reference' => $reference]],
        ];
    }
}
