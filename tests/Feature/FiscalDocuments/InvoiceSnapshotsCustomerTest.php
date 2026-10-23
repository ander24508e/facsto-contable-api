<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceSnapshotsCustomerTest extends InvoiceFeatureTestCase
{
    public function test_invoice_keeps_customer_snapshot_when_customer_changes(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $sale->customer->forceFill(['legal_name' => 'Nombre actualizado'])->save();
        $invoice = $this->actingAs($user)->withHeaders($this->companyHeader($company))->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated()->json('data');
        $this->assertSame('Cliente de prueba', $invoice['customer']['name']);
    }
}
