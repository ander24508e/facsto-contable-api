<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class CannotInvoiceSaleTwiceTest extends InvoiceFeatureTestCase
{
    public function test_repeated_request_returns_the_original_invoice_without_new_sequence(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $uri = '/api/v1/sales/'.$sale->public_id.'/invoice';
        $first = $this->actingAs($user)->postJson($uri)->assertCreated()->json('data');
        $second = $this->actingAs($user)->postJson($uri)->assertCreated()->json('data');
        $this->assertSame($first['accessKey'], $second['accessKey']);
        $this->assertDatabaseHas('document_sequences', ['company_id' => $company->id, 'current_number' => 1]);
    }
}
