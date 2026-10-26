<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class CannotNumberWithMismatchedCertificateTest extends InvoiceFeatureTestCase
{
    public function test_a_certificate_that_no_longer_matches_the_issuer_ruc_blocks_numbering(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        // The issuer RUC changed after the certificate was uploaded.
        $company->forceFill(['ruc' => '1799999999001'])->save();

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertConflict()->assertJsonPath('error.details.pending', ['certificate']);
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_an_unreadable_stored_certificate_blocks_numbering(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        Storage::disk('private')->put($company->digitalCertificates()->firstOrFail()->storage_path, 'corrupted');

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertConflict()->assertJsonPath('error.details.pending', ['certificate_unreadable']);
        $this->assertDatabaseCount('electronic_documents', 0);
    }

    public function test_sales_get_an_internal_consecutive_number_per_company(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $first = $this->makeSale($user, $company);

        $this->actingAs($user)->getJson('/api/v1/sales/'.$first->public_id)->assertOk()->assertJsonPath('data.number', 'V-00001');
        $second = $this->actingAs($user)->postJson('/api/v1/sales', [])->assertCreated();
        $second->assertJsonPath('data.number', 'V-00002');

        $this->actingAs($user)->postJson('/api/v1/sales/'.$first->public_id.'/invoice')->assertCreated();
        $prepared = $this->actingAs($user)->getJson('/api/v1/sales?stage=prepared')->assertOk();
        $prepared->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'V-00001')
            ->assertJsonPath('data.0.document.fullNumber', '001-001-000000001');
        $this->actingAs($user)->getJson('/api/v1/sales?stage=draft')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'V-00002');
    }
}
