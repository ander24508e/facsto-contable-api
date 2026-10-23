<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Catalog\Models\Tax;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceSnapshotsTaxesTest extends InvoiceFeatureTestCase
{
    public function test_invoice_copies_sale_tax_snapshots_into_line_and_summary(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $tax = Tax::query()->create(['tax_type' => 'IVA', 'sri_code' => '2', 'percentage_code' => '4', 'name' => 'IVA 15', 'percentage' => '15.000000', 'valid_from' => today()->subDay(), 'is_active' => true]);
        $sale = $this->makeSale($user, $company, false);
        $line = $sale->details()->firstOrFail();
        $item = $line->item;
        $item->taxes()->attach($tax->id);
        $this->actingAs($user)->withHeaders($this->companyHeader($company))->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id, 'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => FiscalPaymentMethod::query()->create(['code' => 'F01', 'name' => 'Forma fiscal', 'valid_from' => today()->subDay(), 'is_active' => true])->public_id, 'amount' => '11.50']],
        ])->assertOk();
        $invoice = $this->actingAs($user)->withHeaders($this->companyHeader($company))->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated()->json('data');
        $this->assertSame('2', $invoice['lines'][0]['taxes'][0]['taxCode']);
        $this->assertSame('1.50', $invoice['lines'][0]['taxes'][0]['taxAmount']);
        $this->assertSame('1.50', $invoice['taxes'][0]['taxAmount']);
    }
}
