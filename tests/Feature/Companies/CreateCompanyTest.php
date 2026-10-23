<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Models\Company;
use App\Models\User;

final class CreateCompanyTest extends CompanyTestCase
{
    public function test_company_creation_atomically_adds_the_owner_membership(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/companies', [
            'ruc' => '1799999999001',
            'legalName' => 'Condore Demo Cia Ltda',
            'mainAddress' => 'Quito, Ecuador',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.ruc', '1799999999001')
            ->assertJsonPath('data.role', 'OWNER');

        $this->assertDatabaseHas('company_memberships', [
            'company_id' => Company::query()->where('ruc', '1799999999001')->value('id'),
            'user_id' => $user->id,
            'role' => 'OWNER',
            'is_active' => true,
        ]);
    }
}
