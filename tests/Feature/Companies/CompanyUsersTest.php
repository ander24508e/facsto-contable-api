<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class CompanyUsersTest extends CompanyTestCase
{
    public function test_admin_creates_a_login_account_with_its_roles(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $biller = $this->presetRole($company, 'BILLER');

        $response = $this->actingAs($owner)->postJson('/api/v1/company/users', [
            'name' => 'Ana Operadora',
            'email' => 'ana@example.com',
            'phone' => '0991234567',
            'rolePublicIds' => [$biller->public_id],
            'password' => 'Initial12345',
            'password_confirmation' => 'Initial12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.isAdministrator', false)
            ->assertJsonCount(1, 'data.roles');
        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Initial12345', $user->password));

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'Initial12345'])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/company')->assertOk()
            ->assertJsonPath('data.isAdministrator', false)
            ->assertJsonFragment(['sales.create']);
    }

    public function test_a_user_manager_cannot_create_an_administrator_and_no_account_is_left_behind(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $manager = $this->memberAs($company, ['users.view', 'users.manage']);

        $this->actingAs($manager)->postJson('/api/v1/company/users', [
            'name' => 'Nuevo Administrador',
            'email' => 'owner@example.com',
            'rolePublicIds' => $this->roleIds($company, 'ADMIN'),
            'password' => 'Initial12345',
            'password_confirmation' => 'Initial12345',
        ])->assertForbidden()->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_without_users_permission_the_users_are_not_listed_or_created(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $biller = $this->memberAs($company, 'BILLER');

        $this->actingAs($biller)->getJson('/api/v1/company/members')->assertForbidden();
        $this->actingAs($biller)->postJson('/api/v1/company/users', [
            'name' => 'Otro', 'email' => 'otro@example.com', 'rolePublicIds' => $this->roleIds($company, 'VIEWER'),
            'password' => 'Initial12345', 'password_confirmation' => 'Initial12345',
        ])->assertForbidden();
    }

    public function test_member_listing_is_scoped_to_the_authenticated_company(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $local = User::factory()->create(['name' => 'Local Employee']);
        app(AddCompanyMemberAction::class)->execute($company, $owner, $local, $this->roleIds($company, 'VIEWER'));

        $foreignOwner = User::factory()->create();
        $foreignCompany = $this->createCompany($foreignOwner, '1799999999002');
        $foreignUser = User::factory()->create(['name' => 'Foreign Employee']);
        app(AddCompanyMemberAction::class)->execute($foreignCompany, $foreignOwner, $foreignUser, $this->roleIds($foreignCompany, 'BILLER'));

        $this->actingAs($owner)->getJson('/api/v1/company/members?status=ALL&search=Employee')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.userPublicId', $local->public_id)
            ->assertJsonPath('data.0.roles.0.name', 'Viewer');
    }

    public function test_roles_of_another_company_are_refused(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $foreignCompany = $this->createCompany(User::factory()->create(), '1799999999002');

        $this->actingAs($owner)->postJson('/api/v1/company/users', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'rolePublicIds' => $this->roleIds($foreignCompany, 'BILLER'),
            'password' => 'Initial12345', 'password_confirmation' => 'Initial12345',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['errors' => ['rolePublicIds']]]]);
    }

    public function test_inactive_membership_cannot_enter_the_company_and_can_be_reactivated(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $member = User::factory()->create();
        app(AddCompanyMemberAction::class)->execute($company, $owner, $member, $this->roleIds($company, 'BILLER'));

        $this->actingAs($owner)->deleteJson('/api/v1/company/members/'.$member->public_id)
            ->assertOk()->assertJsonPath('data.isActive', false);
        $this->actingAs($member)->getJson('/api/v1/company')->assertForbidden()->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');

        $this->actingAs($owner)->postJson('/api/v1/company/members/'.$member->public_id.'/reactivate')
            ->assertOk()->assertJsonPath('data.isActive', true);
        $this->actingAs($member)->getJson('/api/v1/company')->assertOk();
    }
}
