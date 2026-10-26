<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class SetupCompanyIsolationTest extends SetupTestCase
{
    public function test_setup_always_targets_the_users_own_company(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $companyA = $this->createCompany($ownerA);
        $companyB = $this->createCompany($ownerB);

        $this->actingAs($ownerA)
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
