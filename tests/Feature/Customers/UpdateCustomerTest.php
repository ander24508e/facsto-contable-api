<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class UpdateCustomerTest extends CompanyTestCase
{
    public function test_customer_can_be_updated_within_its_company(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $customer = $this->actingAs($owner)->withHeaders($this->companyHeader($company))
            ->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '0123456789', 'legalName' => 'Antes'])
            ->assertCreated()->json('data');

        $this->withHeaders($this->companyHeader($company))->patchJson('/api/v1/customers/'.$customer['publicId'], ['legalName' => 'Después'])
            ->assertOk()->assertJsonPath('data.legalName', 'Después');
    }
}
