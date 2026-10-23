<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class CannotInvoiceCancelledSaleTest extends InvoiceFeatureTestCase
{
    public function test_cancelled_sale_cannot_be_numbered(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $headers = $this->companyHeader($company);
        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/sales/'.$sale->public_id.'/cancel')->assertOk();
        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertConflict();
    }
}
