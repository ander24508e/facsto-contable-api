<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Access\PermissionCatalog;
use App\Domains\Companies\Models\Role;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Models\User;

final class CompanyRolesTest extends CompanyTestCase
{
    public function test_every_company_starts_with_the_fixed_administrator_role(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner, '1799999999001');

        $this->actingAs($owner)->getJson('/api/v1/company/roles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Administrador')
            ->assertJsonPath('data.0.isFixed', true)
            ->assertJsonPath('data.0.usersCount', 1)
            ->assertJsonPath('data.1.name', 'Usuario')
            ->assertJsonPath('data.1.isFixed', false)
            ->assertJsonPath('data.1.permissions', []);
        $this->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.isAdministrator', true);
    }

    public function test_a_role_is_created_with_its_permissions_and_they_decide_what_the_user_can_do(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');

        $role = $this->actingAs($owner)->postJson('/api/v1/company/roles', [
            'name' => 'Vendedor',
            'notes' => 'Atiende clientes',
            'permissions' => ['customers.view', 'customers.create'],
        ])->assertCreated()
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.permissions', ['customers.create', 'customers.view'])
            ->json('data');

        $seller = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/v1/company/members', ['userPublicId' => $seller->public_id, 'rolePublicIds' => [$role['publicId']]])->assertCreated();

        $this->actingAs($seller)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($seller)->getJson('/api/v1/items')->assertForbidden();
        $this->actingAs($seller)->patchJson('/api/v1/company', ['commercialName' => 'Otro'])->assertForbidden();

        // Turning the role off takes its permissions away.
        $this->actingAs($owner)->patchJson('/api/v1/company/roles/'.$role['publicId'], ['isActive' => false])->assertOk();
        $this->actingAs($seller)->getJson('/api/v1/customers')->assertForbidden();
        $this->actingAs($seller)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_the_administrator_role_is_not_edited_and_names_are_unique(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $admin = $this->presetRole($company, 'ADMIN');

        $this->actingAs($owner)->patchJson('/api/v1/company/roles/'.$admin->public_id, ['isActive' => false])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ROLE_FIXED');
        $this->postJson('/api/v1/company/roles', ['name' => 'Cajero'])->assertCreated();
        $this->postJson('/api/v1/company/roles', ['name' => '  cajero '])->assertConflict()->assertJsonPath('error.code', 'ROLE_DUPLICATE');
        $this->postJson('/api/v1/company/roles', ['name' => 'Otro', 'permissions' => ['company.update']])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['errors' => ['permissions']]]]);
    }

    public function test_each_module_has_its_own_card_and_vertical_modules_appear_only_when_contracted(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');

        $modules = $this->actingAs($owner)->getJson('/api/v1/company/roles/modules')->assertOk()->json('data');
        $this->assertNotContains('carwash', array_column($modules, 'module'));
        foreach ($modules as $module) {
            foreach ($module['permissions'] as $permission) {
                // A module only shows its own permissions.
                $this->assertStringStartsWith($module['module'].'.', $permission['key']);
            }
        }

        CompanyEntitlement::query()->create(['company_id' => $company->id, 'feature' => 'carwash', 'status' => EntitlementStatus::ENABLED]);
        app(ModuleRegistry::class)->forget($company);
        $carwash = collect($this->actingAs($owner)->getJson('/api/v1/company/roles/modules')->json('data'))->firstWhere('module', 'carwash');
        $this->assertSame('Lavadora', $carwash['name']);
        $this->assertContains('carwash.assignable', array_column($carwash['permissions'], 'key'));
    }

    public function test_the_catalog_keys_are_unique_and_namespaced_by_module(): void
    {
        $blocks = app(PermissionCatalog::class)->blocks();
        $keys = [];
        foreach ($blocks as $module => $block) {
            $this->assertNotEmpty($block['permissions'], $module);
            foreach (array_keys($block['permissions']) as $key) {
                $this->assertStringStartsWith($module.'.', $key);
                $keys[] = $key;
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_only_users_permission_reaches_roles(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $viewer = $this->memberAs($company, ['users.view']);

        $this->actingAs($viewer)->getJson('/api/v1/company/roles')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/company/roles', ['name' => 'Nuevo'])->assertForbidden();
        $this->actingAs($this->memberAs($company, ['sales.view']))->getJson('/api/v1/company/roles')->assertForbidden();
    }

    public function test_replacing_a_role_removes_the_previous_permissions_and_refuses_multiple_roles(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $member = $this->memberAs($company, ['customers.view']);
        $role = $this->roleWith($company, ['sales.view'], 'Ventas');
        $admin = $this->presetRole($company, 'ADMIN');

        $this->actingAs($member)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($owner)->putJson('/api/v1/company/members/'.$member->public_id.'/roles', [
            'rolePublicIds' => [$admin->public_id, $role->public_id],
        ])->assertUnprocessable();
        $this->putJson('/api/v1/company/members/'.$member->public_id.'/roles', [
            'rolePublicIds' => [$role->public_id],
        ])->assertOk()->assertJsonCount(1, 'data.roles')->assertJsonPath('data.roles.0.publicId', $role->public_id);
        $this->actingAs($member)->getJson('/api/v1/customers')->assertForbidden();
        $this->getJson('/api/v1/sales')->assertOk();
    }

    public function test_usuario_keeps_its_identity_but_its_permissions_can_be_configured(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $role = Role::query()->where('company_id', $company->id)->where('name', 'Usuario')->firstOrFail();
        $url = '/api/v1/company/roles/'.$role->public_id;

        $this->actingAs($owner)->patchJson($url, ['name' => 'Otro'])->assertUnprocessable();
        $this->patchJson($url, ['isActive' => false])->assertUnprocessable();
        $this->patchJson($url, ['permissions' => ['customers.view']])->assertOk()
            ->assertJsonPath('data.isFixed', false)->assertJsonPath('data.permissions', ['customers.view']);
    }
}
