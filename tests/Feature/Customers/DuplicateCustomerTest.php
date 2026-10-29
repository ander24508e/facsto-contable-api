<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class DuplicateCustomerTest extends CompanyTestCase
{
    public function test_identification_is_unique_within_company(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $this->actingAs($owner);
        $payload = ['identificationType' => 'CEDULA', 'identification' => '0123456782', 'legalName' => 'Cliente'];
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $this->postJson('/api/v1/customers', $payload)->assertStatus(409)->assertJsonPath('error.code', 'CUSTOMER_DUPLICATE');
    }
}
