<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class SetupCompanyIsolationTest extends SetupTestCase
{
    public function test_user_cannot_read_or_configure_another_company_setup(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $companyA = $this->createCompany($ownerA);
        $companyB = $this->createCompany($ownerB);

        $this->actingAs($ownerA)
            ->withHeaders($this->companyHeader($companyB))
            ->getJson('/api/v1/setup/status')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');

        $this->actingAs($ownerA)
            ->withHeaders($this->companyHeader($companyA))
            ->putJson('/api/v1/setup/issuer', [
                'company_id' => $companyB->id,
                'ruc' => '1790016919001',
                'legalName' => 'Emisor A',
                'mainAddress' => 'Quito, Ecuador',
            ])
            ->assertOk();

        $this->assertDatabaseHas('companies', ['id' => $companyA->id, 'legal_name' => 'Emisor A']);
        $this->assertDatabaseHas('companies', ['id' => $companyB->id, 'legal_name' => null]);
    }
}
