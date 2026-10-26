<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Enums\CompanyStatus;
use App\Models\User;

final class CompanyContextTest extends CompanyTestCase
{
    public function test_company_context_comes_from_the_users_membership_without_any_header(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito']);

        $this->actingAs($user)
            ->getJson('/api/v1/establishments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '001');
    }

    public function test_a_header_naming_another_company_is_ignored(): void
    {
        $userA = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany(User::factory()->create(), '1799999999002');
        $companyB->establishments()->create(['code' => '009', 'name' => 'Ajeno', 'address' => 'Guayaquil']);

        $this->actingAs($userA)
            ->getJson('/api/v1/establishments', ['X-Facsto-Company' => $companyB->public_id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userA)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.publicId', $companyA->public_id);
    }

    public function test_user_without_an_active_membership_has_no_company_access(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/company')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');
    }

    public function test_inactive_company_is_rejected(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $company->forceFill(['status' => CompanyStatus::DISABLED])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/company')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'COMPANY_INACTIVE');
    }
}
