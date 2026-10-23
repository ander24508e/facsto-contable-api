<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SetupProgressionTest extends SetupTestCase
{
    public function test_setup_state_advances_only_when_backend_data_is_ready(): void
    {
        Storage::fake('private');
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);
        $headers = $this->companyHeader($company);

        $this->actingAs($owner)->withHeaders($headers)->getJson('/api/v1/setup/status')
            ->assertJsonPath('data.state', 'ISSUER_REQUIRED');

        $this->configureIssuer($company);
        $this->actingAs($owner)->withHeaders($headers)->getJson('/api/v1/setup/status')
            ->assertJsonPath('data.state', 'EMISSION_REQUIRED');

        $this->configureEmission($company);
        $this->actingAs($owner)->withHeaders($headers)->getJson('/api/v1/setup/status')
            ->assertJsonPath('data.state', 'CERTIFICATE_REQUIRED');

        $this->actingAs($owner)->withHeaders($headers)->post('/api/v1/setup/certificate', [
            'certificate' => $this->validPkcs12Upload(),
            'password' => 'test-password',
        ])->assertCreated();

        $certificate = DB::table('digital_certificates')->first();
        $this->assertSame('VALID', $certificate->status);
        $this->assertTrue(Storage::disk('private')->exists($certificate->storage_path));
        $this->actingAs($owner)->withHeaders($headers)->getJson('/api/v1/setup/status')
            ->assertJsonPath('data.state', 'READY')
            ->assertJsonPath('data.certificateReady', true);
    }
}
