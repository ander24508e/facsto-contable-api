<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class ListCustomersTest extends CompanyTestCase
{
    public function test_customer_list_is_paginated_and_filterable(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $this->actingAs($owner)->withHeaders($this->companyHeader($company));
        foreach (['Ana Pérez', 'Luis Mora'] as $index => $name) {
            $this->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '012345678'.($index + 1), 'legalName' => $name])->assertCreated();
        }

        $this->getJson('/api/v1/customers?search=ana&per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
    }
}
