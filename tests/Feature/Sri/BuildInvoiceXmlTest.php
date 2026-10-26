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
        Storage::fake('private');
        config(['sri.software_provider.ruc' => '1790012345001']);
    }

    public function test_numbered_invoice_builds_valid_private_unsigned_xml_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $invoiceData = $this->actingAs($user)
            ->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $invoiceData['publicId'])->firstOrFail();
        $invoice->paymentMethods()->firstOrFail()->forceFill(['method_code' => '01'])->save();

        $path = '/api/v1/invoices/'.$invoice->public_id.'/xml';
        $this->actingAs($user)->postJson($path)->assertOk()->assertJsonPath('data.status', 'XML_BUILT');
        $this->actingAs($user)->postJson($path)->assertUnprocessable();

        $artifact = $invoice->artifacts()->where('type', DocumentArtifactType::UNSIGNED_XML->value)->firstOrFail();
        $this->assertSame(1, $artifact->version);
        $this->assertSame(hash('sha256', Storage::disk('private')->get($artifact->path)), $artifact->sha256);
        $this->assertStringContainsString('<claveAcceso>'.$invoice->access_key.'</claveAcceso>', Storage::disk('private')->get($artifact->path));
        $this->assertSame(DocumentStatus::XML_BUILT, $invoice->refresh()->status);
        $this->assertDatabaseCount('document_artifacts', 1);
    }

    public function test_invoice_xml_endpoint_rejects_another_company_document(): void
    {
        $userA = User::factory()->create();
        $companyA = $this->readyCompany($userA);
        $saleA = $this->makeSale($userA, $companyA);
        $invoiceA = $this->actingAs($userA)->postJson('/api/v1/sales/'.$saleA->public_id.'/invoice')->assertCreated()->json('data');

        $userB = User::factory()->create();
        $this->readyCompany($userB, '1799999999002', '1790016918001');
        $this->actingAs($userB)->postJson('/api/v1/invoices/'.$invoiceA['publicId'].'/xml')->assertForbidden();
    }

    public function test_missing_provider_ruc_blocks_xml_without_changing_number_or_status(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $invoiceData = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $invoiceData['publicId'])->firstOrFail();
        $invoice->forceFill(['provider_ruc' => null])->save();

        $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice->public_id.'/xml')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.reason_code', 'SRI_PROVIDER_NOT_CONFIGURED');

        self::assertSame(DocumentStatus::NUMBERED, $invoice->refresh()->status);
        $this->assertDatabaseCount('document_artifacts', 0);
        self::assertSame('000000001', $invoice->sequential);
    }
}
