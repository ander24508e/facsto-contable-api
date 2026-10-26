<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceSnapshotsTaxesTest extends InvoiceFeatureTestCase
{
    public function test_invoice_copies_sale_tax_snapshots_into_line_and_summary(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $tax = $this->companyIva($company, '4');
        $sale = $this->makeSale($user, $company, false);
        $line = $sale->details()->firstOrFail();
        $item = $line->item;
        $item->taxes()->sync([$tax->id => ['company_id' => $company->id]]);
        $this->actingAs($user)->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id, 'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => FiscalPaymentMethod::query()->create(['code' => '20', 'name' => 'Otros con utilización del sistema financiero', 'valid_from' => today()->subDay(), 'is_active' => true])->public_id, 'amount' => '11.50']],
        ])->assertOk();
        $invoice = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $this->assertSame('2', $invoice['lines'][0]['taxes'][0]['taxCode']);
        $this->assertSame('1.50', $invoice['lines'][0]['taxes'][0]['taxAmount']);
        $this->assertSame('1.50', $invoice['taxes'][0]['taxAmount']);
    }
}
