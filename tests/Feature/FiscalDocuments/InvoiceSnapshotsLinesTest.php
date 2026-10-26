<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceSnapshotsLinesTest extends InvoiceFeatureTestCase
{
    public function test_invoice_lines_are_copied_as_historical_data(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $line = $sale->details()->firstOrFail();
        $line->item->forceFill(['name' => 'Producto cambiado'])->save();
        $invoice = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated()->json('data');
        $this->assertSame('Servicio', $invoice['lines'][0]['description']);
        $this->assertSame('10.000000', $invoice['lines'][0]['unitPrice']);
    }
}
