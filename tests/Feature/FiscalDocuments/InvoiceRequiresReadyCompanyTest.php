<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

final class InvoiceRequiresReadyCompanyTest extends SalesTestCase
{
    public function test_deferred_setup_still_blocks_invoice_creation(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company);
        $company->fiscalSettings()->create(['accounting_required' => false, 'setup_deferred_at' => now()]);
        $this->actingAs($user)->withHeaders($this->companyHeader($company))->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_SETUP_INCOMPLETE');
        $this->assertDatabaseCount('electronic_documents', 0);
    }
}
