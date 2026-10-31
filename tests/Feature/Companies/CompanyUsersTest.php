<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class CompanyUsersTest extends CompanyTestCase
{
    public function test_admin_creates_a_login_account_and_active_company_membership(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);

        $response = $this->actingAs($admin)->postJson('/api/v1/company/users', [
            'name' => 'Ana Operadora',
            'email' => 'ana@example.com',
            'phone' => '0991234567',
            'role' => 'BILLER',
            'password' => 'Initial12345',
            'password_confirmation' => 'Initial12345',
        ]);

        $response->assertCreated()->assertJsonPath('data.email', 'ana@example.com')->assertJsonPath('data.role', 'BILLER')->assertJsonPath('data.isActive', true);
        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Initial12345', $user->password));
        $this->assertDatabaseHas('company_memberships', ['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'BILLER', 'is_active' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'Initial12345'])->assertOk();
        $this->getJson('/api/v1/company')->assertOk();
    }

    public function test_admin_cannot_create_an_owner_and_no_account_is_left_behind(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);

        $this->actingAs($admin)->postJson('/api/v1/company/users', [
            'name' => 'Nuevo Propietario',
            'email' => 'owner@example.com',
            'role' => 'OWNER',
            'password' => 'Initial12345',
            'password_confirmation' => 'Initial12345',
        ])->assertForbidden()->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_member_listing_is_scoped_to_the_authenticated_company(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);
        $local = User::factory()->create(['name' => 'Local Employee']);
        app(AddCompanyMemberAction::class)->execute($company, $admin, $local, CompanyRole::VIEWER);

        $foreignOwner = User::factory()->create();
        $foreignCompany = $this->createCompany($foreignOwner, '1799999999002');
        $foreignUser = User::factory()->create(['name' => 'Foreign Employee']);
        app(AddCompanyMemberAction::class)->execute($foreignCompany, $foreignOwner, $foreignUser, CompanyRole::BILLER);

        $this->actingAs($admin)->getJson('/api/v1/company/members?status=ALL&search=Employee')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.userPublicId', $local->public_id);
    }

    public function test_role_catalog_exposes_only_roles_the_admin_can_assign(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);

        $this->actingAs($admin)->getJson('/api/v1/company/roles')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'OWNER')
            ->assertJsonPath('data.0.canAssign', false)
            ->assertJsonPath('data.1.code', 'ADMIN')
            ->assertJsonPath('data.1.canAssign', false)
            ->assertJsonPath('data.2.code', 'BILLER')
            ->assertJsonPath('data.2.canAssign', true)
            ->assertJsonPath('data.3.code', 'VIEWER')
            ->assertJsonPath('data.3.canAssign', true);
    }

    public function test_inactive_membership_cannot_enter_the_company_and_can_be_reactivated(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);
        $member = User::factory()->create();
        app(AddCompanyMemberAction::class)->execute($company, $admin, $member, CompanyRole::BILLER);

        $this->actingAs($admin)->deleteJson('/api/v1/company/members/'.$member->public_id)
            ->assertOk()->assertJsonPath('data.isActive', false);
        $this->actingAs($member)->getJson('/api/v1/company')->assertForbidden()->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');

        $this->actingAs($admin)->postJson('/api/v1/company/members/'.$member->public_id.'/reactivate')
            ->assertOk()->assertJsonPath('data.isActive', true);
        $this->actingAs($member)->getJson('/api/v1/company')->assertOk();
    }
}
