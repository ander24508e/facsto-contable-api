<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class CompanyMembersTest extends CompanyTestCase
{
    public function test_admin_adds_an_employee_to_their_own_company(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $employee = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/v1/company/members', ['userPublicId' => $employee->public_id, 'role' => 'BILLER'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'BILLER');

        $this->assertDatabaseHas('company_memberships', ['company_id' => $company->id, 'user_id' => $employee->id, 'role' => 'BILLER']);
    }

    public function test_a_user_of_another_company_cannot_be_added(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner, '1799999999001');
        $foreignUser = User::factory()->create();
        $this->createCompany($foreignUser, '1799999999002');

        $this->actingAs($owner)
            ->postJson('/api/v1/company/members', ['userPublicId' => $foreignUser->public_id, 'role' => 'BILLER'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'USER_BELONGS_TO_ANOTHER_COMPANY');
    }
}
