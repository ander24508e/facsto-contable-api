<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceRequiresReadySaleTest extends InvoiceFeatureTestCase
{
    public function test_sale_without_payment_snapshot_cannot_be_numbered(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company, false);
        $this->actingAs($user)->withHeaders($this->companyHeader($company))->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertUnprocessable()->assertJsonPath('error.code', 'SALE_NOT_READY_FOR_INVOICE');
    }
}
