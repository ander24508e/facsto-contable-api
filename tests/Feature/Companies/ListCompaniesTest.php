<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;

final class ListCompaniesTest extends CompanyTestCase
{
    public function test_user_sees_only_companies_with_an_active_membership(): void
    {
        $user = User::factory()->create();
        $visible = $this->createCompany($user, '1799999999001');
        $hidden = $this->createCompany(User::factory()->create(), '1799999999002');
        $hidden->memberships()->create([
            'user_id' => $user->id,
            'role' => CompanyRole::VIEWER,
            'is_active' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.publicId', $visible->public_id);
    }
}
