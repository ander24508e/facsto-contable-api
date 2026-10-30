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
        $this->actingAs($owner);
        foreach (['Ana Pérez', 'Luis Mora'] as $index => $name) {
            $this->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => ['0123456782', '1710034065'][$index], 'legalName' => $name])->assertCreated();
        }

        $this->getJson('/api/v1/customers?search=ana&per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
    }

    public function test_consumidor_final_is_used_in_sales_but_never_listed(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner, '1799999999001');
        $finalConsumer = $this->actingAs($owner)->postJson('/api/v1/customers/final-consumer')->assertSuccessful()->json('data.publicId');
        $this->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '0123456782', 'legalName' => 'Ana Pérez'])->assertCreated();

        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/customers?search=9999')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customers?identification_type=FINAL_CONSUMER')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/customers/{$finalConsumer}")->assertOk();
    }
}
