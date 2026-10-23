<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class ConfigureIssuerTest extends SetupTestCase
{
    public function test_owner_can_configure_issuer_with_a_valid_ecuadorian_ruc(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);

        $this->actingAs($owner)
            ->withHeaders($this->companyHeader($company))
            ->putJson('/api/v1/setup/issuer', [
                'ruc' => '1790016919001',
                'legalName' => 'Emisor de prueba',
                'commercialName' => 'FACSTO Test',
                'mainAddress' => 'Quito, Ecuador',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'EMISSION_REQUIRED');

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'ruc' => '1790016919001',
            'legal_name' => 'Emisor de prueba',
        ]);
    }

    public function test_private_company_ruc_is_not_rejected_by_a_natural_person_checksum(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);

        $this->actingAs($owner)
            ->withHeaders($this->companyHeader($company))
            ->putJson('/api/v1/setup/issuer', [
                'ruc' => '1790016919001',
                'legalName' => 'Sociedad privada de prueba',
                'mainAddress' => 'Quito, Ecuador',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'EMISSION_REQUIRED');
    }

    public function test_structurally_invalid_ruc_is_rejected(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);

        $this->actingAs($owner)
            ->withHeaders($this->companyHeader($company))
            ->putJson('/api/v1/setup/issuer', [
                'ruc' => '1712345672001',
                'legalName' => 'Emisor inválido',
                'mainAddress' => 'Quito, Ecuador',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_ECUADOR_RUC');
    }
}
