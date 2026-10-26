<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class SetupStatusTest extends SetupTestCase
{
    public function test_empty_company_requires_issuer_configuration(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner);

        $this->actingAs($owner)
            ->getJson('/api/v1/setup/status')
            ->assertOk()
            ->assertJsonPath('data.state', 'ISSUER_REQUIRED')
            ->assertJsonPath('data.issuerReady', false)
            ->assertJsonPath('data.emissionReady', false)
            ->assertJsonPath('data.certificateReady', false);
    }
}
