<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class EmissionPointIsolationTest extends CompanyTestCase
{
    public function test_user_cannot_update_an_emission_point_from_another_company(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $establishment = $companyB->establishments()->create(['code' => '001', 'name' => 'Matriz B', 'address' => 'Guayaquil']);
        $point = $establishment->emissionPoints()->create([
            'company_id' => $companyB->id,
            'code' => '001',
            'name' => 'Caja B',
        ]);

        $this->actingAs($userA)
            ->patchJson('/api/v1/emission-points/'.$point->public_id, ['name' => 'Modificado'])
            ->assertNotFound();

        $this->assertDatabaseHas('emission_points', ['id' => $point->id, 'name' => 'Caja B']);
    }
}
