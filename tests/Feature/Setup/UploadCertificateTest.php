<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class UploadCertificateTest extends SetupTestCase
{
    public function test_valid_p12_is_inspected_and_stored_without_exposing_secrets(): void
    {
        Storage::fake('private');
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);
        $this->configureIssuer($company);
        $this->configureEmission($company);

        $response = $this->actingAs($owner)
            ->post('/api/v1/setup/certificate', [
                'certificate' => $this->validPkcs12Upload(),
                'password' => 'test-password',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonMissingPath('data.storagePath')
            ->assertJsonMissingPath('data.password');

        $certificatePath = $response->json('data.publicId');
        $this->assertDatabaseHas('digital_certificates', [
            'company_id' => $company->id,
            'status' => 'VALID',
            'is_active' => true,
        ]);
        $this->assertNotEmpty($certificatePath);
        $this->assertDatabaseMissing('digital_certificates', ['password_ciphertext' => 'test-password']);
    }
}
