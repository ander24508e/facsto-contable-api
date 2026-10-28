<?php

namespace Tests\Feature\Companies;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** «Activar facturación electrónica» and the artisan qualification number. */
final class ElectronicInvoicingSwitchTest extends InvoiceFeatureTestCase
{
    public function test_the_switch_only_turns_on_with_the_whole_checklist(): void
    {
        $user = User::factory()->create();
        $company = $this->preparableCompany($user);

        $this->actingAs($user)->getJson('/api/v1/company')->assertOk()
            ->assertJsonPath('data.electronicInvoicing.enabled', false)
            ->assertJsonPath('data.electronicInvoicing.requirements.companyData', true)
            ->assertJsonPath('data.electronicInvoicing.requirements.certificate', false);
        $this->actingAs($user)->putJson('/api/v1/company/electronic-invoicing', ['enabled' => true])
            ->assertConflict()
            ->assertJsonPath('error.code', 'ELECTRONIC_INVOICING_REQUIREMENTS_PENDING')
            ->assertJsonPath('error.details.pending', ['certificate']);
        $this->assertFalse((bool) $company->fiscalSettings->refresh()->sri_submission_enabled);

        $this->installReadyCertificate($company);
        $this->actingAs($user)->putJson('/api/v1/company/electronic-invoicing', ['enabled' => true])->assertOk()
            ->assertJsonPath('data.electronicInvoicing.enabled', true);
        $this->assertTrue((bool) $company->fiscalSettings->refresh()->sri_submission_enabled);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'company.electronic_invoicing_enabled']);

        $this->actingAs($user)->putJson('/api/v1/company/electronic-invoicing', ['enabled' => false])->assertOk()
            ->assertJsonPath('data.electronicInvoicing.enabled', false);
    }

    public function test_a_biller_cannot_switch_it(): void
    {
        $owner = User::factory()->create();
        $company = $this->readyCompany($owner);
        $biller = User::factory()->create();
        $company->memberships()->create(['user_id' => $biller->id, 'role' => 'BILLER', 'is_active' => true, 'joined_at' => now()]);

        $this->actingAs($biller)->putJson('/api/v1/company/electronic-invoicing', ['enabled' => true])->assertForbidden();
    }

    public function test_changing_the_ruc_switches_it_off(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->actingAs($user)->putJson('/api/v1/company/electronic-invoicing', ['enabled' => true])->assertOk();

        $this->actingAs($user)->post('/api/v1/company/fiscal-identity', [
            'ruc' => '1710034065001', 'legalName' => 'Nuevo', 'commercialName' => 'Nuevo', 'mainAddress' => 'Quito',
            'email' => 'nuevo@example.com', 'phone' => '0981234567',
            'certificate' => $this->pkcs12Upload('1710034065001'), 'password' => 'test-password',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.electronicInvoicing.enabled', false);
    }

    public function test_the_artisan_qualification_is_saved_and_frozen_in_each_invoice(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->actingAs($user)->patchJson('/api/v1/fiscal-profile', ['artisanQualificationNumber' => 'JNDA-12345'])->assertOk()
            ->assertJsonPath('data.artisanQualificationNumber', 'JNDA-12345');

        $sale = $this->makeSale($user, $company);
        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $this->actingAs($user)->patchJson('/api/v1/fiscal-profile', ['artisanQualificationNumber' => null])->assertOk();

        $this->assertSame('JNDA-12345', ElectronicDocument::query()->where('public_id', $data['publicId'])->value('artisan_qualification_number'));
    }
}
