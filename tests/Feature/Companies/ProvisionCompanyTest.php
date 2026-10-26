<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProvisionCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function provision(array $overrides = []): int
    {
        return $this->artisan('facsto:empresa:crear', [
            'slug' => 'lavadora-endara',
            '--ruc' => '1790016919001',
            '--direccion' => 'Santo Domingo, Ecuador',
            '--operador-nombre' => 'Operador Condore',
            '--operador-correo' => 'soporte+lavadora@condoresoftware.com',
            '--cliente-nombre' => 'Administrador Endara',
            '--cliente-correo' => 'Admin@Endara.com',
            ...$overrides,
        ])->run();
    }

    public function test_command_activates_a_company_with_its_structure_and_two_administrators(): void
    {
        $this->assertSame(0, $this->provision());

        $company = Company::query()->where('slug', 'lavadora-endara')->sole();
        $this->assertSame('1790016919001', $company->ruc);
        $this->assertSame(['OWNER', 'ADMIN'], $company->memberships()->orderBy('id')->get()->map(fn ($membership): string => $membership->role->value)->all());
        $this->assertTrue(User::query()->where('email', 'admin@endara.com')->exists());

        $establishment = $company->establishments()->sole();
        $this->assertSame(['001', true], [$establishment->code, $establishment->is_main]);
        $this->assertSame('001', $establishment->emissionPoints()->sole()->code);
        $this->assertSame('TEST', $company->fiscalSettings->sri_environment->value);

        $client = User::query()->where('email', 'admin@endara.com')->sole();
        $this->actingAs($client)->getJson('/api/v1/setup/status')
            ->assertOk()
            ->assertJsonPath('data.onboardingRequired', true);
    }

    public function test_duplicate_slug_ruc_or_email_is_rejected_without_partial_data(): void
    {
        $this->provision();

        $this->assertSame(1, $this->provision(['--ruc' => '1790085783001', '--operador-correo' => 'otro@condoresoftware.com', '--cliente-correo' => 'otro@endara.com']));
        $this->assertSame(1, $this->provision(['slug' => 'otra-empresa', '--operador-correo' => 'otro@condoresoftware.com', '--cliente-correo' => 'otro@endara.com']));
        $this->assertSame(1, $this->provision(['slug' => 'otra-empresa', '--ruc' => '1790085783001']));
        $this->assertSame(1, $this->provision(['slug' => 'Slug Inválido']));

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 2);
    }
}
