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
            '--operador-nombre' => 'Operador Condore',
            '--operador-correo' => 'soporte+lavadora@condoresoftware.com',
            '--cliente-nombre' => 'Administrador Endara',
            '--cliente-correo' => 'Admin@Endara.com',
            ...$overrides,
        ])->run();
    }

    public function test_command_activates_a_company_without_any_prefilled_fiscal_data(): void
    {
        $this->assertSame(0, $this->provision());

        $company = Company::query()->where('slug', 'lavadora-endara')->sole();
        $this->assertSame(['OWNER', 'ADMIN'], $company->memberships()->orderBy('id')->get()->map(fn ($membership): string => $membership->role->value)->all());
        $this->assertTrue(User::query()->where('email', 'admin@endara.com')->exists());

        // The client enters every fiscal datum in the welcome flow; nothing looks "already done".
        foreach (['ruc', 'legal_name', 'commercial_name', 'main_address', 'email', 'phone'] as $attribute) {
            $this->assertNull($company->{$attribute}, $attribute);
        }
        $this->assertSame(0, $company->establishments()->count());
        $this->assertSame('TEST', $company->fiscalSettings->sri_environment->value);

        $client = User::query()->where('email', 'admin@endara.com')->sole();
        $this->actingAs($client)->getJson('/api/v1/setup/status')
            ->assertOk()
            ->assertJsonPath('data.onboardingRequired', true)
            ->assertJsonPath('data.issuerReady', false)
            ->assertJsonPath('data.emissionReady', false)
            ->assertJsonPath('data.certificateReady', false);
        $this->actingAs($client)->getJson('/api/v1/company')->assertOk()
            ->assertJsonPath('data.ruc', null)
            ->assertJsonPath('data.legalName', null)
            ->assertJsonPath('data.activeCertificate', null);
    }

    public function test_duplicate_slug_or_email_is_rejected_without_partial_data(): void
    {
        $this->provision();

        $this->assertSame(1, $this->provision(['--operador-correo' => 'otro@condoresoftware.com', '--cliente-correo' => 'otro@endara.com']));
        $this->assertSame(1, $this->provision(['slug' => 'otra-empresa']));
        $this->assertSame(1, $this->provision(['slug' => 'Slug Inválido', '--operador-correo' => 'otro@condoresoftware.com', '--cliente-correo' => 'otro@endara.com']));

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 2);
    }
}
