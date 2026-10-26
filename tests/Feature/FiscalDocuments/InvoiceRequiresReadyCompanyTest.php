<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceRequiresReadyCompanyTest extends InvoiceFeatureTestCase
{
    public function test_missing_issuer_and_emission_structure_blocks_numbering(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_SETUP_INCOMPLETE');
        $this->assertDatabaseCount('electronic_documents', 0);
    }

    public function test_without_a_certificate_no_sequential_number_or_access_key_is_assigned(): void
    {
        $user = User::factory()->create();
        $company = $this->preparableCompany($user);
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertConflict()
            ->assertJsonPath('error.code', 'FISCAL_SETUP_INCOMPLETE')
            ->assertJsonPath('error.details.pending', ['certificate']);

        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('document_sequences', 0);
        $this->assertSame('DRAFT', $sale->refresh()->status->value);
    }
}
