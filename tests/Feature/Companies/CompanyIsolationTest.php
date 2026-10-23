<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class CompanyIsolationTest extends CompanyTestCase
{
    public function test_user_cannot_read_or_modify_another_company(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');

        $this->actingAs($userA)
            ->getJson('/api/v1/companies/'.$companyB->public_id)
            ->assertForbidden();

        $this->actingAs($userA)
            ->patchJson('/api/v1/companies/'.$companyB->public_id, ['legalName' => 'Acceso indebido'])
            ->assertForbidden();

        $this->assertDatabaseHas('companies', ['id' => $companyB->id, 'legal_name' => 'Empresa 1799999999002']);
    }
}
