<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class CompanyIsolationTest extends CompanyTestCase
{
    public function test_user_only_reads_and_modifies_their_own_company(): void
    {
        $userA = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany(User::factory()->create(), '1799999999002');

        $this->actingAs($userA)
            ->patchJson('/api/v1/company', ['legalName' => 'Nombre actualizado', 'company_id' => $companyB->id])
            ->assertOk()
            ->assertJsonPath('data.publicId', $companyA->public_id)
            ->assertJsonPath('data.legalName', 'Nombre actualizado');

        $this->assertDatabaseHas('companies', ['id' => $companyB->id, 'legal_name' => 'Empresa 1799999999002']);
    }

    public function test_company_listing_and_self_service_creation_no_longer_exist(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->getJson('/api/v1/companies')->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/companies', [])->assertNotFound();
        $this->actingAs($user)->getJson('/api/v1/companies/'.$company->public_id)->assertNotFound();
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
    }
}
