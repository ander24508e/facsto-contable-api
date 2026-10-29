<?php

namespace Tests\Feature\Customers;

use App\Domains\Customers\Models\Customer;
use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class CustomerLookupAndMergeTest extends CompanyTestCase
{
    public function test_a_typed_identification_is_detected_and_its_duplicates_found(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $this->actingAs($user)->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '1710034065', 'legalName' => 'Ana'])->assertCreated();

        $this->getJson('/api/v1/customers/lookup?identification=1710034065001')->assertOk()
            ->assertJsonPath('data.detectedType', 'RUC')
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.relatedCustomers.0.identification', '1710034065');
        $this->getJson('/api/v1/customers/lookup?identification=1710034066')->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_an_invalid_cedula_or_ruc_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '1710034066', 'legalName' => 'X'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CUSTOMER_IDENTIFICATION');
        $this->postJson('/api/v1/customers', ['identificationType' => 'RUC', 'identification' => '1710034066001', 'legalName' => 'X'])
            ->assertUnprocessable();
    }

    public function test_a_duplicate_is_merged_into_the_kept_customer(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $this->actingAs($user);
        $kept = $this->postJson('/api/v1/customers', ['identificationType' => 'RUC', 'identification' => '1710034065001', 'legalName' => 'Ana RUC'])->json('data.publicId');
        $duplicate = $this->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '1710034065', 'legalName' => 'Ana'])->json('data.publicId');

        $this->postJson("/api/v1/customers/{$kept}/merge", ['sourcePublicId' => $duplicate])->assertOk()
            ->assertJsonPath('data.publicId', $kept);
        $this->assertFalse(Customer::query()->where('public_id', $duplicate)->firstOrFail()->is_active);
        $this->postJson("/api/v1/customers/{$kept}/merge", ['sourcePublicId' => $kept])->assertUnprocessable();
        $this->getJson("/api/v1/customers/{$kept}/fiscal-summary")->assertOk()
            ->assertJsonPath('data.invoicedTotal', '0.00')
            ->assertJsonPath('data.documents', []);
    }
}
