<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceIsolationTest extends InvoiceFeatureTestCase
{
    public function test_invoice_is_not_visible_under_another_company_context(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $companyA = $this->readyCompany($owner);
        $companyB = $this->createCompany($other, '1799999998001');
        $sale = $this->makeSale($owner, $companyA);
        $invoice = $this->actingAs($owner)->withHeaders($this->companyHeader($companyA))->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated()->json('data');
        $this->actingAs($other)->withHeaders($this->companyHeader($companyB))->getJson('/api/v1/invoices/'.$invoice['publicId'])->assertForbidden();
    }
}
