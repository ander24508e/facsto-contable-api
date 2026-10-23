<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class CompanyContextTest extends CompanyTestCase
{
    public function test_tenant_endpoint_requires_a_valid_company_header_and_sets_context(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito']);

        $this->actingAs($user)
            ->getJson('/api/v1/establishments')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_COMPANY_CONTEXT');

        $this->actingAs($user)
            ->getJson('/api/v1/establishments', $this->companyHeader($company))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '001');
    }
}
