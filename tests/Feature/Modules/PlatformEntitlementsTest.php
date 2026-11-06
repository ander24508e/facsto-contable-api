<?php

namespace Tests\Feature\Modules;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Domains\Modules\Models\EntitlementSync;
use App\Models\User;
use App\Shared\Integration\CondoreSignature;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** Condore Platform sends each company's entitlements: signed, versioned, whole-state, logged. */
final class PlatformEntitlementsTest extends InvoiceFeatureTestCase
{
    private const SECRET = 'platform-test-secret-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.platform.secret' => self::SECRET]);
    }

    public function test_platform_sets_the_whole_state_and_older_versions_change_nothing(): void
    {
        $company = $this->readyCompany(User::factory()->create());

        $this->push($company, ['version' => 3, 'entitlements' => [
            ['feature' => 'carwash', 'status' => 'ENABLED'],
            ['feature' => 'carwash.storefront', 'status' => 'ENABLED'],
        ], 'limits' => ['users' => 5]])
            ->assertOk()
            ->assertJsonPath('meta.applied', true)
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.limits.users', 5)
            ->assertJsonCount(2, 'data.entitlements');

        $this->push($company, ['version' => 2, 'entitlements' => []])
            ->assertOk()->assertJsonPath('meta.applied', false)->assertJsonPath('data.version', 3);
        $this->assertSame('ENABLED', CompanyEntitlement::query()->where('feature', 'carwash')->value('status')->value);

        // A feature missing from a newer message is turned off.
        $this->push($company, ['version' => 4, 'entitlements' => [['feature' => 'carwash', 'status' => 'READ_ONLY']]])
            ->assertOk()->assertJsonPath('meta.applied', true);
        $this->assertSame('DISABLED', CompanyEntitlement::query()->where('feature', 'carwash.storefront')->value('status')->value);
        $this->assertSame('READ_ONLY', CompanyEntitlement::query()->where('feature', 'carwash')->value('status')->value);

        $this->assertSame(['APPLIED', 'STALE', 'APPLIED'], EntitlementSync::query()->orderBy('id')->pluck('result')->all());
        $this->signed('GET', "/internal/v1/platform/companies/{$company->public_id}/entitlements")
            ->assertOk()->assertJsonPath('data.version', 4)->assertJsonPath('data.entitlements.0.feature', 'carwash');
    }

    public function test_unknown_features_and_missing_parent_module_are_rejected(): void
    {
        $company = $this->readyCompany(User::factory()->create());

        $this->push($company, ['version' => 1, 'entitlements' => [['feature' => 'casino', 'status' => 'ENABLED']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ENTITLEMENTS_INVALID');
        $this->push($company, ['version' => 1, 'entitlements' => [['feature' => 'carwash.storefront', 'status' => 'ENABLED']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ENTITLEMENTS_INVALID');
        $this->assertSame(0, CompanyEntitlement::query()->count());
    }

    public function test_only_platform_signature_is_accepted(): void
    {
        $company = $this->readyCompany(User::factory()->create());
        $body = ['version' => 1, 'entitlements' => [['feature' => 'carwash', 'status' => 'ENABLED']]];

        $this->putJson("/internal/v1/platform/companies/{$company->public_id}/entitlements", $body)
            ->assertUnauthorized()->assertJsonPath('error.code', 'INTERNAL_SIGNATURE_INVALID');
        $this->push($company, $body, secret: 'otro-secreto')->assertUnauthorized();
        $this->push($company, $body, client: 'carwash')->assertUnauthorized();
        $this->assertSame(0, CompanyEntitlement::query()->count());
    }

    public function test_the_console_stands_in_for_platform(): void
    {
        $company = $this->readyCompany(User::factory()->create());

        $this->artisan('modules:entitlement', ['company' => $company->slug, 'feature' => 'carwash'])->assertSuccessful();
        $this->artisan('modules:entitlement', ['company' => $company->slug, 'feature' => 'carwash', '--read-only' => true])->assertSuccessful();
        $this->artisan('modules:entitlement', ['company' => $company->slug, 'feature' => 'nada'])->assertFailed();

        $entitlement = CompanyEntitlement::query()->sole();
        $this->assertSame(['READ_ONLY', 'CONSOLE'], [$entitlement->status->value, $entitlement->source]);
    }

    /** @param array<string, mixed> $body */
    private function push(Company $company, array $body, string $secret = self::SECRET, string $client = 'platform'): TestResponse
    {
        return $this->signed('PUT', "/internal/v1/platform/companies/{$company->public_id}/entitlements", $body, $secret, $client);
    }

    /** @param array<string, mixed>|null $body */
    private function signed(string $method, string $uri, ?array $body = null, string $secret = self::SECRET, string $client = 'platform'): TestResponse
    {
        $json = $body === null ? '' : (string) json_encode($body);
        $timestamp = time();
        $server = [
            'HTTP_X_CONDORE_CLIENT' => $client,
            'HTTP_X_CONDORE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CONDORE_SIGNATURE' => CondoreSignature::sign($secret, $timestamp, $method, $uri, null, $json),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        return $this->call($method, $uri, [], [], [], $server, $json);
    }
}
