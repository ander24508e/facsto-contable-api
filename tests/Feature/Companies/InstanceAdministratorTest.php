<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Models\Role;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;
use Database\Seeders\InstanceAdministratorSeeder;
use Database\Seeders\SriCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One deployment, one client: the company and Condore's support account come from the environment, no container.
 * The support account stays visible to the client but locked; only Dokploy changes it.
 */
final class InstanceAdministratorTest extends TestCase
{
    use RefreshDatabase;

    private function deploy(string $password = 'Clave-de-soporte-2026', string $email = 'soporte@condoresoftware.com'): void
    {
        config([
            'instance.company_slug' => 'principal',
            'instance.support.name' => 'Soporte Condore',
            'instance.support.email' => $email,
            'instance.support.password' => $password,
        ]);
        $this->seed(InstanceAdministratorSeeder::class);
    }

    private function support(): User
    {
        return User::query()->where('email', 'soporte@condoresoftware.com')->sole();
    }

    /** The client's own administrator, next to the support account. */
    private function clientAdministrator(): User
    {
        $company = Company::query()->where('slug', 'principal')->sole();
        $client = User::factory()->create();
        app(CompanyRoles::class)->makeAdministrator($company->memberships()->create(['user_id' => $client->id, 'is_active' => true, 'joined_at' => now()]));

        return $client;
    }

    public function test_the_first_deploy_leaves_the_company_and_the_support_administrator_ready_to_sign_in(): void
    {
        $this->seed(SriCatalogSeeder::class);
        $this->deploy();

        $company = Company::query()->where('slug', 'principal')->sole();
        $membership = $company->memberships()->where('user_id', $this->support()->id)->sole();
        $this->assertSame([Role::ADMINISTRATOR], $membership->roles()->pluck('name')->all());
        $this->assertSame('TEST', $company->fiscalSettings->sri_environment->value);

        // Signs in with the Dokploy password and lands in the welcome flow of its company.
        $this->postJson('/api/v1/auth/login', ['email' => 'soporte@condoresoftware.com', 'password' => 'Clave-de-soporte-2026'])->assertOk();
        $this->getJson('/api/v1/setup/status')->assertOk()->assertJsonPath('data.onboardingRequired', true);
    }

    public function test_every_deploy_is_idempotent_and_dokploy_holds_the_password(): void
    {
        $this->deploy();
        $support = $this->support();
        $membership = CompanyMembership::query()->where('user_id', $support->id)->sole();
        $membership->forceFill(['is_active' => false])->save();

        $this->deploy('Otra-clave-soporte-77');

        $this->assertSame(1, Company::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertTrue($membership->refresh()->is_active);
        $this->assertTrue(Hash::check('Otra-clave-soporte-77', $support->refresh()->password));
    }

    public function test_without_the_variables_nothing_is_created(): void
    {
        config(['instance.support.email' => null, 'instance.support.password' => null]);
        $this->seed(InstanceAdministratorSeeder::class);

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_password_outside_the_rule_never_stops_the_installation(): void
    {
        $this->deploy();

        foreach (['123456', 'soloLetrasLargas'] as $weak) {
            $this->deploy($weak);
        }

        // The installation kept starting and the support account kept the last valid password.
        $this->assertTrue(Hash::check('Clave-de-soporte-2026', $this->support()->password));
    }

    public function test_the_client_sees_the_support_account_but_cannot_edit_deactivate_or_change_its_role(): void
    {
        $this->deploy();
        $client = $this->clientAdministrator();
        $support = $this->support();
        $usuario = Role::query()->where('name', 'Usuario')->sole();

        $listed = collect($this->actingAs($client)->getJson('/api/v1/company/members')->assertOk()->json('data'))->keyBy('email');
        $this->assertTrue($listed['soporte@condoresoftware.com']['isSupport']);
        $this->assertFalse($listed[$client->email]['isSupport']);

        $this->deleteJson('/api/v1/company/members/'.$support->public_id)->assertForbidden()->assertJsonPath('error.code', 'SUPPORT_ACCOUNT_LOCKED');
        $this->putJson('/api/v1/company/members/'.$support->public_id.'/roles', ['rolePublicIds' => [$usuario->public_id]])
            ->assertForbidden()->assertJsonPath('error.code', 'SUPPORT_ACCOUNT_LOCKED');
        $this->patchJson('/api/v1/company/users/'.$support->public_id, [
            'name' => 'Otro Nombre', 'email' => 'otro@example.com', 'version' => $support->updated_at->toIso8601String(),
        ])->assertForbidden()->assertJsonPath('error.code', 'SUPPORT_ACCOUNT_LOCKED');

        $membership = CompanyMembership::query()->where('user_id', $support->id)->sole();
        $this->assertTrue($membership->is_active);
        $this->assertSame([Role::ADMINISTRATOR], $membership->roles()->pluck('name')->all());
        $this->assertSame('soporte@condoresoftware.com', $support->refresh()->email);
    }

    public function test_the_support_account_changes_its_name_email_and_password_only_from_the_installation(): void
    {
        $this->deploy();
        $support = $this->support();

        $this->actingAs($support)->putJson('/api/v1/me/password', ['password' => 'Nueva-clave-2027', 'passwordConfirmation' => 'Nueva-clave-2027'])
            ->assertForbidden()->assertJsonPath('error.code', 'SUPPORT_ACCOUNT_LOCKED');
        $this->patchJson('/api/v1/me', ['name' => 'Otro'])->assertForbidden()->assertJsonPath('error.code', 'SUPPORT_ACCOUNT_LOCKED');
        $this->patchJson('/api/v1/me', ['phone' => '0999999999'])->assertOk()->assertJsonPath('data.isSupport', true);

        $this->assertTrue(Hash::check('Clave-de-soporte-2026', $support->refresh()->password));
    }

    public function test_after_changing_the_support_email_the_previous_account_can_be_deactivated(): void
    {
        $this->deploy(email: 'anterior@condoresoftware.com');
        $previous = User::query()->where('email', 'anterior@condoresoftware.com')->sole();
        $this->deploy();

        $this->actingAs($this->support())->deleteJson('/api/v1/company/members/'.$previous->public_id)
            ->assertOk()->assertJsonPath('data.isActive', false);
    }

    public function test_the_deploy_leaves_every_member_with_a_single_role(): void
    {
        $this->deploy();
        $company = Company::query()->where('slug', 'principal')->sole();
        $administrator = Role::query()->where('company_id', $company->id)->where('is_fixed', true)->sole();
        $usuario = Role::query()->where('company_id', $company->id)->where('name', 'Usuario')->sole();
        $seller = Role::query()->create(['company_id' => $company->id, 'name' => 'Vendedor', 'is_fixed' => false, 'is_active' => true]);

        $withAdministrator = $company->memberships()->create(['user_id' => User::factory()->create()->id, 'is_active' => true, 'joined_at' => now()]);
        $withAdministrator->roles()->sync([$administrator->id => ['company_id' => $company->id], $usuario->id => ['company_id' => $company->id]]);
        $withoutAdministrator = $company->memberships()->create(['user_id' => User::factory()->create()->id, 'is_active' => true, 'joined_at' => now()]);
        $withoutAdministrator->roles()->sync([$usuario->id => ['company_id' => $company->id], $seller->id => ['company_id' => $company->id]]);

        $this->deploy();

        $this->assertSame([Role::ADMINISTRATOR], $withAdministrator->roles()->pluck('name')->all());
        $this->assertSame(['Vendedor'], $withoutAdministrator->roles()->pluck('name')->all());
    }
}
