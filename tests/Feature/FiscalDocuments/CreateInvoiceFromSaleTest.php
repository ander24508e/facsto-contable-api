<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class CreateInvoiceFromSaleTest extends InvoiceFeatureTestCase
{
    public function test_ready_sale_creates_numbered_invoice_and_repeat_returns_same_document(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $headers = $this->companyHeader($company);
        $path = '/api/v1/sales/'.$sale->public_id.'/invoice';
        $first = $this->actingAs($user)->withHeaders($headers)->postJson($path)->assertCreated()->json('data');
        $this->assertSame('NUMBERED', $first['status']);
        $this->assertSame('001-001-000000001', $first['fullNumber']);
        $this->assertSame(49, strlen($first['accessKey']));
        $this->actingAs($user)->withHeaders($headers)->postJson($path)->assertCreated()->assertJsonPath('data.publicId', $first['publicId']);
        $this->assertDatabaseCount('electronic_documents', 1);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'DOCUMENT_PREPARED']);
    }
}
