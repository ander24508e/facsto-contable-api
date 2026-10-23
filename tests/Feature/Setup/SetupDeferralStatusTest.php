<?php

namespace Tests\Feature\Setup;

use App\Models\User;

final class SetupDeferralStatusTest extends SetupTestCase
{
    public function test_deferring_setup_does_not_make_a_company_fiscally_ready(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user);
        $headers = $this->companyHeader($company);

        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/setup/defer')
            ->assertOk()->assertJsonPath('data.state', 'ISSUER_REQUIRED')->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.canIssueDocuments', false)->assertJsonPath('data.deferred', true);

        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/setup/status')
            ->assertOk()->assertJsonPath('data.state', 'ISSUER_REQUIRED')->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.canIssueDocuments', false)->assertJsonPath('data.deferred', true);

        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/setup/resume')
            ->assertOk()->assertJsonPath('data.state', 'ISSUER_REQUIRED')->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.deferred', false);
    }
}
