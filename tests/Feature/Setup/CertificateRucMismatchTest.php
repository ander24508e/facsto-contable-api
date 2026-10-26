<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class CertificateRucMismatchTest extends SetupTestCase
{
    public function test_a_certificate_of_another_ruc_is_rejected_and_its_key_is_not_kept(): void
    {
        Storage::fake('private');
        [$owner, $company] = $this->configuredCompany();

        $this->upload($owner, $this->pkcs12Upload('1799999999001'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_MISMATCH')
            ->assertJsonPath('error.details.companyRuc', '1790016919001')
            ->assertJsonPath('error.details.certificateRuc', '1799999999001');

        $this->assertDatabaseHas('digital_certificates', [
            'company_id' => $company->id, 'status' => 'RUC_MISMATCH', 'validation_error_code' => 'RUC_MISMATCH',
            'is_active' => false, 'storage_path' => null, 'password_ciphertext' => null, 'subject_ruc' => '1799999999001',
        ]);
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'certificate.rejected']);
        $this->actingAs($owner)->getJson('/api/v1/setup/status')->assertJsonPath('data.canIssueDocuments', false);
    }

    public function test_a_certificate_without_an_identifiable_ruc_is_rejected(): void
    {
        Storage::fake('private');
        [$owner, $company] = $this->configuredCompany();

        $this->upload($owner, $this->pkcs12Upload(null))
            ->assertUnprocessable()->assertJsonPath('error.code', 'CERTIFICATE_RUC_NOT_FOUND');

        $this->assertDatabaseHas('digital_certificates', ['company_id' => $company->id, 'status' => 'RUC_NOT_FOUND', 'is_active' => false]);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_expired_certificate_is_rejected(): void
    {
        Storage::fake('private');
        [$owner] = $this->configuredCompany();

        $certificate = $this->pkcs12Upload('1790016919001', days: 30);
        $this->travel(40)->days();

        $this->upload($owner, $certificate)
            ->assertUnprocessable()->assertJsonPath('error.code', 'CERTIFICATE_EXPIRED');
    }

    public function test_a_certificate_that_is_not_yet_valid_is_rejected(): void
    {
        Storage::fake('private');
        [$owner] = $this->configuredCompany();
        $certificate = $this->pkcs12Upload('1790016919001');
        $this->travel(-1)->days();

        $this->upload($owner, $certificate)
            ->assertUnprocessable()->assertJsonPath('error.code', 'CERTIFICATE_NOT_YET_VALID');
    }

    public function test_a_certificate_of_the_issuer_ruc_becomes_active(): void
    {
        Storage::fake('private');
        [$owner, $company] = $this->configuredCompany();

        $this->upload($owner, $this->validPkcs12Upload())->assertCreated()
            ->assertJsonPath('data.status', 'VALID')->assertJsonPath('data.subjectRuc', '1790016919001');

        $this->assertDatabaseHas('digital_certificates', ['company_id' => $company->id, 'status' => 'VALID', 'is_active' => true, 'subject_ruc' => '1790016919001']);
        $this->actingAs($owner)->getJson('/api/v1/setup/status')->assertJsonPath('data.canIssueDocuments', true);
    }

    private function configuredCompany(): array
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);
        $this->configureIssuer($company);
        $this->configureEmission($company);

        return [$owner, $company->refresh()];
    }

    private function upload(User $owner, $file)
    {
        return $this->actingAs($owner)->post('/api/v1/setup/certificate', ['certificate' => $file, 'password' => 'test-password'], ['Accept' => 'application/json']);
    }
}
