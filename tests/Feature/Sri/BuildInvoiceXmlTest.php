<?php

namespace Tests\Feature\Sri;

use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class BuildInvoiceXmlTest extends InvoiceFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sri.software_provider.ruc' => '1790012345001']);
    }

    public function test_issue_stores_a_valid_private_unsigned_xml_and_never_rebuilds_a_signed_document(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $invoiceData = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $invoiceData['publicId'])->firstOrFail();

        $artifact = $invoice->artifacts()->where('type', DocumentArtifactType::UNSIGNED_XML->value)->firstOrFail();
        $this->assertSame(1, $artifact->version);
        $this->assertSame(hash('sha256', Storage::disk('private')->get($artifact->path)), $artifact->sha256);
        $this->assertStringContainsString('<claveAcceso>'.$invoice->access_key.'</claveAcceso>', Storage::disk('private')->get($artifact->path));
        $this->assertNotNull($invoice->refresh()->xml_built_at);

        // A signed document is never rebuilt: the technical endpoint refuses it.
        $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice->public_id.'/xml')
            ->assertUnprocessable()->assertJsonPath('error.details.reason_code', 'DOCUMENT_NOT_BUILDABLE');
        $this->assertSame(DocumentStatus::SIGNED, $invoice->refresh()->status);
        $this->assertSame(1, $invoice->artifacts()->where('type', DocumentArtifactType::UNSIGNED_XML->value)->count());
    }

    public function test_invoice_xml_endpoint_rejects_another_company_document(): void
    {
        $userA = User::factory()->create();
        $companyA = $this->readyCompany($userA);
        $saleA = $this->makeSale($userA, $companyA);
        $invoiceA = $this->actingAs($userA)->postJson('/api/v1/sales/'.$saleA->public_id.'/issue')->assertCreated()->json('data');

        $userB = User::factory()->create();
        $this->readyCompany($userB, '1799999999002', '1790016918001');
        $this->actingAs($userB)->postJson('/api/v1/invoices/'.$invoiceA['publicId'].'/xml')->assertForbidden();
        $this->actingAs($userB)->getJson('/api/v1/invoices/'.$invoiceA['publicId'].'/artifacts')->assertForbidden();
    }

    public function test_an_xml_failure_keeps_the_number_and_issue_resumes_without_a_new_sequential(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        // Simulates a numbered document whose frozen provider RUC is missing (Anexo 26).
        ElectronicDocument::created(fn (ElectronicDocument $document) => $document->forceFill(['provider_ruc' => null])->saveQuietly());

        $error = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.reason_code', 'SRI_PROVIDER_NOT_CONFIGURED')
            ->assertJsonPath('error.details.invoiceStatus', 'NUMBERED')
            ->json('error.details');

        $invoice = ElectronicDocument::query()->where('public_id', $error['invoicePublicId'])->firstOrFail();
        $this->assertSame(DocumentStatus::NUMBERED, $invoice->status);
        $this->assertSame('SRI_PROVIDER_NOT_CONFIGURED', $invoice->last_error_code);
        $this->assertDatabaseCount('document_artifacts', 0);

        $invoice->forceFill(['provider_ruc' => '1790012345001'])->saveQuietly();
        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertOk()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('data.fullNumber', '001-001-000000001')
            ->assertJsonPath('data.lastErrorCode', null);
        $this->assertDatabaseHas('document_sequences', ['company_id' => $company->id, 'current_number' => 1]);
    }
}
