<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class CreateInvoiceFromSaleTest extends InvoiceFeatureTestCase
{
    public function test_ready_sale_is_issued_up_to_signed_and_repeat_returns_same_document(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $path = '/api/v1/sales/'.$sale->public_id.'/issue';
        $first = $this->actingAs($user)->postJson($path)->assertCreated()->json('data');
        // Transmission is disabled in tests: F04 + F05 finish and the document waits SIGNED.
        $this->assertSame('SIGNED', $first['status']);
        $this->assertSame('TRANSPORT_DISABLED', $first['transmissionBlockedReason']);
        $this->assertSame('001-001-000000001', $first['fullNumber']);
        $this->assertSame(49, strlen($first['accessKey']));
        $this->actingAs($user)->postJson($path)->assertOk()->assertJsonPath('data.publicId', $first['publicId']);
        $this->assertDatabaseCount('electronic_documents', 1);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'DOCUMENT_PREPARED']);
    }
}
