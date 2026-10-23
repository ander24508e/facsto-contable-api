<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class ConfigureEmissionStructureTest extends SetupTestCase
{
    public function test_emission_structure_and_test_environment_are_saved_as_primary(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);
        $this->configureIssuer($company);

        $this->actingAs($owner)
            ->withHeaders($this->companyHeader($company))
            ->putJson('/api/v1/setup/emission', [
                'establishmentCode' => '003',
                'establishmentName' => 'Matriz',
                'establishmentAddress' => 'Quito, Ecuador',
                'emissionPointCode' => '007',
                'emissionPointName' => 'Principal',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'CERTIFICATE_REQUIRED');

        $this->assertDatabaseHas('establishments', [
            'company_id' => $company->id,
            'code' => '003',
            'is_main' => true,
        ]);
        $this->assertDatabaseHas('emission_points', [
            'company_id' => $company->id,
            'code' => '007',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('company_fiscal_settings', [
            'company_id' => $company->id,
            'sri_environment' => 'TEST',
            'emission_type' => 'NORMAL',
        ]);
    }
}
