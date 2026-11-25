<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use Database\Seeders\InstanceAdministratorSeeder;
use Database\Seeders\SriCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/** One deployment, one client: the company and Condore's support account come from the environment, no container. */
final class InstanceAdministratorTest extends TestCase
{
    use RefreshDatabase;

    private function deploy(string $password = 'clave-de-soporte-segura', string $email = 'soporte@condoresoftware.com'): void
    {
        config([
            'instance.company_slug' => 'principal',
            'instance.support.name' => 'Soporte Condore',
            'instance.support.email' => $email,
            'instance.support.password' => $password,
        ]);
        $this->seed(InstanceAdministratorSeeder::class);
    }

    public function test_the_first_deploy_leaves_the_company_and_the_support_administrator_ready_to_sign_in(): void
    {
        $this->seed(SriCatalogSeeder::class);
        $this->deploy();

        $company = Company::query()->where('slug', 'principal')->sole();
        $support = User::query()->where('email', 'soporte@condoresoftware.com')->sole();
        $this->assertTrue($company->memberships()->where('user_id', $support->id)->sole()->isAdministrator());
        $this->assertSame('TEST', $company->fiscalSettings->sri_environment->value);

        // Signs in with the Dokploy password and lands in the welcome flow of its company.
        $this->postJson('/api/v1/auth/login', ['email' => 'soporte@condoresoftware.com', 'password' => 'clave-de-soporte-segura'])->assertOk();
        $this->getJson('/api/v1/setup/status')->assertOk()->assertJsonPath('data.onboardingRequired', true);
    }

    public function test_every_deploy_is_idempotent_and_dokploy_holds_the_password(): void
    {
        $this->deploy();
        $support = User::query()->where('email', 'soporte@condoresoftware.com')->sole();
        $membership = CompanyMembership::query()->where('user_id', $support->id)->sole();
        $membership->forceFill(['is_active' => false])->save();

        $this->deploy('otra-clave-de-soporte');

        $this->assertSame(1, Company::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertTrue($membership->refresh()->is_active);
        $this->assertTrue(Hash::check('otra-clave-de-soporte', $support->refresh()->password));
    }

    public function test_without_the_variables_nothing_is_created(): void
    {
        config(['instance.support.email' => null, 'instance.support.password' => null]);
        $this->seed(InstanceAdministratorSeeder::class);

        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_short_password_stops_the_deploy_with_a_clear_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SUPPORT_ADMIN_PASSWORD debe tener al menos 12 caracteres.');

        $this->deploy('corta');
    }
}
