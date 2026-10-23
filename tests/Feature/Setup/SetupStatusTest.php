<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class SetupStatusTest extends SetupTestCase
{
    public function test_user_can_create_a_company_shell_then_begin_issuer_setup(): void
    {
        $owner = User::factory()->create();

        $companyResponse = $this->actingAs($owner)
            ->postJson('/api/v1/companies', [])
            ->assertCreated()
            ->assertJsonPath('data.ruc', null)
            ->assertJsonPath('data.legalName', null);

        $companyPublicId = $companyResponse->json('data.publicId');

        $this->withHeader('X-Facsto-Company', $companyPublicId)
            ->getJson('/api/v1/setup/status')
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUER_REQUIRED');
    }

    public function test_empty_company_requires_issuer_configuration(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);

        $this->actingAs($owner)
            ->withHeaders($this->companyHeader($company))
            ->getJson('/api/v1/setup/status')
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUER_REQUIRED')
            ->assertJsonPath('data.issuerReady', false)
            ->assertJsonPath('data.emissionReady', false)
            ->assertJsonPath('data.certificateReady', false);
    }
}
