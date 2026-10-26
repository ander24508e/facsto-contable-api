<?php

namespace Tests\Feature\Setup;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class OnboardingFlowTest extends SetupTestCase
{
    private function addMember(Company $company, CompanyRole $role): User
    {
        $user = User::factory()->create();
        $company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'is_active' => true, 'joined_at' => now()]);

        return $user;
    }

    private function setupStatus(User $user, Company $company)
    {
        return $this->actingAs($user)->getJson('/api/v1/setup/status')->assertOk();
    }

    public function test_the_first_two_administrators_see_the_welcome_flow_and_later_members_never_do(): void
    {
        $operator = User::factory()->create();
        $company = $this->createCompany($operator);
        $clientAdmin = $this->addMember($company, CompanyRole::ADMIN);
        $thirdAdmin = $this->addMember($company, CompanyRole::ADMIN);
        $biller = $this->addMember($company, CompanyRole::BILLER);
        $viewer = $this->addMember($company, CompanyRole::VIEWER);

        $this->setupStatus($operator, $company)->assertJsonPath('data.onboardingRequired', true);
        $this->setupStatus($clientAdmin, $company)->assertJsonPath('data.onboardingRequired', true);
        $this->setupStatus($thirdAdmin, $company)->assertJsonPath('data.onboardingRequired', false);
        $this->setupStatus($biller, $company)->assertJsonPath('data.onboardingRequired', false);
        $this->setupStatus($viewer, $company)->assertJsonPath('data.onboardingRequired', false);
    }

    public function test_skipping_only_affects_the_administrator_who_skips_and_never_reopens(): void
    {
        $operator = User::factory()->create();
        $company = $this->createCompany($operator);
        $clientAdmin = $this->addMember($company, CompanyRole::ADMIN);

        $this->actingAs($operator)->postJson('/api/v1/setup/defer')
            ->assertOk()
            ->assertJsonPath('data.onboardingRequired', false)
            ->assertJsonPath('data.state', 'ISSUER_REQUIRED')
            ->assertJsonPath('data.canIssueDocuments', false);

        $this->setupStatus($operator, $company)->assertJsonPath('data.onboardingRequired', false);
        $this->setupStatus($clientAdmin, $company)->assertJsonPath('data.onboardingRequired', true);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'company.onboarding_skipped']);
    }

    public function test_employees_cannot_skip_the_company_setup(): void
    {
        $company = $this->createCompany(User::factory()->create());
        $biller = $this->addMember($company, CompanyRole::BILLER);

        $this->actingAs($biller)->postJson('/api/v1/setup/defer')
            ->assertForbidden();
    }

    public function test_finishing_the_last_step_completes_the_flow_so_it_does_not_return_later(): void
    {
        Storage::fake('private');
        $operator = User::factory()->create();
        $company = $this->createCompany($operator);

        $this->configureIssuer($company);
        $this->configureEmission($company);
        $this->actingAs($operator)->post('/api/v1/setup/certificate', [
            'certificate' => $this->validPkcs12Upload(),
            'password' => 'test-password',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotNull($company->memberships()->where('user_id', $operator->id)->value('onboarding_completed_at'));

        $company->digitalCertificates()->update(['is_active' => false]);
        $this->setupStatus($operator, $company)
            ->assertJsonPath('data.state', 'CERTIFICATE_REQUIRED')
            ->assertJsonPath('data.onboardingRequired', false);
    }
}
