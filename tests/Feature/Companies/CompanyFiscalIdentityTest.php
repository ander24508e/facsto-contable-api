<?php

namespace Tests\Feature\Companies;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** "Datos de la empresa": data + RUC + electronic signature in one all-or-nothing save. */
final class CompanyFiscalIdentityTest extends InvoiceFeatureTestCase
{
    private const NEW_RUC = '1710034065001';

    public function test_a_company_replaces_the_demo_ruc_and_its_signature_in_one_save(): void
    {
        $user = User::factory()->create();
        // Fully configured with the demo RUC and no signature, like a fresh subscription.
        $company = $this->preparableCompany($user, '1799999999001', '1799999999001');

        $this->save($user, ['ruc' => self::NEW_RUC], $this->pkcs12Upload(self::NEW_RUC))
            ->assertOk()
            ->assertJsonPath('data.ruc', self::NEW_RUC)
            ->assertJsonPath('data.activeCertificate.usable', true);

        $this->assertSame(self::NEW_RUC, $company->refresh()->ruc);
        $this->actingAs($user)->getJson('/api/v1/setup/status')->assertJsonPath('data.canIssueDocuments', true);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'company.ruc_changed']);
    }

    public function test_a_rejected_signature_saves_nothing(): void
    {
        $user = User::factory()->create();
        $company = $this->preparableCompany($user, '1799999999001', '1799999999001');

        $this->save($user, ['ruc' => self::NEW_RUC, 'legalName' => 'Otro nombre'], $this->pkcs12Upload('1790016919001'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_MISMATCH')
            ->assertJsonPath('error.details.companyRuc', self::NEW_RUC)
            ->assertJsonPath('error.details.certificateRuc', '1790016919001');

        $company->refresh();
        $this->assertSame('1799999999001', $company->ruc);
        $this->assertNotSame('Otro nombre', $company->legal_name);
        $this->assertSame(0, $company->digitalCertificates()->where('is_active', true)->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_the_client_saves_only_what_it_has_without_the_signature(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $company->forceFill(['ruc' => null, 'legal_name' => null, 'main_address' => null])->save();

        $this->actingAs($user)->post('/api/v1/company/fiscal-identity', ['ruc' => '1720127180001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.ruc', '1720127180001')
            ->assertJsonPath('data.legalName', null)
            ->assertJsonPath('data.activeCertificate', null);
        $this->actingAs($user)->post('/api/v1/company/fiscal-identity', ['legalName' => 'ENDARA BENAVIDES ANDERSON DANIEL', 'phone' => ''], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.ruc', '1720127180001')
            ->assertJsonPath('data.legalName', 'ENDARA BENAVIDES ANDERSON DANIEL')
            ->assertJsonPath('data.electronicInvoicing.requirements.certificate', false);
    }

    public function test_a_signature_needs_a_ruc_to_be_checked_against(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $company->forceFill(['ruc' => null])->save();

        $this->actingAs($user)->post('/api/v1/company/fiscal-identity', ['certificate' => $this->pkcs12Upload(self::NEW_RUC), 'password' => 'test-password'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ruc'], 'error.details.errors');
    }

    public function test_data_can_be_updated_without_reuploading_a_valid_signature(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);

        $this->save($user, ['ruc' => '1790016919001', 'legalName' => 'Razón actualizada'])
            ->assertOk()
            ->assertJsonPath('data.legalName', 'Razón actualizada');
        $this->assertSame(1, $company->digitalCertificates()->count());
    }

    public function test_every_field_is_validated(): void
    {
        $user = User::factory()->create();
        $this->preparableCompany($user, '1799999999001', '1799999999001');

        $this->save($user, ['ruc' => '179001691900', 'email' => 'no-es-correo', 'phone' => '09-8138'], $this->pkcs12Upload(self::NEW_RUC))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ruc', 'email', 'phone'], 'error.details.errors');
        $this->save($user, ['ruc' => '1700000000001'], $this->pkcs12Upload(self::NEW_RUC))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ruc'], 'error.details.errors');
    }

    public function test_the_ruc_cannot_change_while_documents_are_in_process(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->assertJsonPath('data.status', 'SIGNED');

        $this->save($user, ['ruc' => self::NEW_RUC], $this->pkcs12Upload(self::NEW_RUC))
            ->assertConflict()
            ->assertJsonPath('error.code', 'COMPANY_RUC_CHANGE_BLOCKED')
            ->assertJsonPath('error.details.pendingDocuments', 1);
        $this->assertSame('1790016919001', $company->refresh()->ruc);
        // Onboarding cannot be used to bypass it either.
        $this->actingAs($user)->putJson('/api/v1/setup/issuer', ['ruc' => self::NEW_RUC, 'legalName' => 'X', 'mainAddress' => 'Quito'])
            ->assertConflict()->assertJsonPath('error.code', 'COMPANY_RUC_CHANGE_BLOCKED');
    }

    public function test_a_new_ruc_starts_its_own_numbering_and_the_previous_one_is_kept(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $first = $this->issue($user, $company);
        $first->forceFill(['status' => DocumentStatus::AUTHORIZED])->save();

        $this->save($user, ['ruc' => self::NEW_RUC], $this->pkcs12Upload(self::NEW_RUC))->assertOk();
        $second = $this->issue($user, $company);

        $this->assertSame('001-001-000000001', $first->full_number);
        $this->assertSame('1790016919001', $first->refresh()->issuer_ruc);
        $this->assertSame('001-001-000000001', $second->full_number);
        $this->assertSame(self::NEW_RUC, $second->issuer_ruc);
        $this->assertStringContainsString(self::NEW_RUC, $second->access_key);
        $this->assertDatabaseHas('document_sequences', ['company_id' => $company->id, 'issuer_ruc' => '1790016919001', 'current_number' => 1]);
        $this->assertDatabaseHas('document_sequences', ['company_id' => $company->id, 'issuer_ruc' => self::NEW_RUC, 'current_number' => 1]);
    }

    public function test_the_initial_sequential_continues_another_system_and_locks_after_the_first_invoice(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $point = $company->emissionPoints()->firstOrFail();
        $uri = '/api/v1/emission-points/'.$point->public_id.'/sequence';

        $this->actingAs($user)->putJson($uri, ['initialSequential' => 0])->assertUnprocessable();
        $this->actingAs($user)->putJson($uri, ['initialSequential' => 125])->assertOk()
            ->assertJsonPath('data.sequence.initialSequential', 125)
            ->assertJsonPath('data.sequence.nextSequential', 125)
            ->assertJsonPath('data.sequence.locked', false);

        $this->assertSame('001-001-000000125', $this->issue($user, $company)->full_number);

        $this->actingAs($user)->putJson($uri, ['initialSequential' => 1])->assertConflict()
            ->assertJsonPath('error.code', 'DOCUMENT_SEQUENCE_LOCKED');
        $this->actingAs($user)->getJson('/api/v1/emission-points')->assertOk()
            ->assertJsonPath('data.0.sequence.locked', true)
            ->assertJsonPath('data.0.sequence.nextSequential', 126);
    }

    private function issue(User $user, $company): ElectronicDocument
    {
        $sale = $this->makeSale($user, $company);
        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');

        return ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
    }

    private function save(User $user, array $overrides = [], $certificate = null)
    {
        $payload = [
            'ruc' => '1790016919001',
            'legalName' => 'Emisor válido',
            'commercialName' => 'FACSTO Test',
            'mainAddress' => 'Quito, Ecuador',
            'email' => 'facturas@example.com',
            'phone' => '0981234567',
            ...$overrides,
        ];
        if ($certificate !== null) {
            $payload['certificate'] = $certificate;
            $payload['password'] = 'test-password';
        }

        return $this->actingAs($user)->post('/api/v1/company/fiscal-identity', $payload, ['Accept' => 'application/json']);
    }
}
