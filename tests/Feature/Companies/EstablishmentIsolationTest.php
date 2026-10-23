<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class EstablishmentIsolationTest extends CompanyTestCase
{
    public function test_user_cannot_update_an_establishment_from_another_company(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $foreignEstablishment = $companyB->establishments()->create(['code' => '001', 'name' => 'Matriz B', 'address' => 'Guayaquil']);

        $this->actingAs($userA)
            ->patchJson('/api/v1/establishments/'.$foreignEstablishment->public_id, ['name' => 'Modificado'], $this->companyHeader($companyA))
            ->assertNotFound();

        $this->assertDatabaseHas('establishments', ['id' => $foreignEstablishment->id, 'name' => 'Matriz B']);
    }

    public function test_emission_point_cannot_be_created_under_a_foreign_establishment(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $foreignEstablishment = $companyB->establishments()->create(['code' => '001', 'name' => 'Matriz B', 'address' => 'Guayaquil']);

        $this->actingAs($userA)
            ->postJson('/api/v1/emission-points', [
                'establishmentPublicId' => $foreignEstablishment->public_id,
                'code' => '001',
                'name' => 'Caja B',
            ], $this->companyHeader($companyA))
            ->assertNotFound();
    }
}
