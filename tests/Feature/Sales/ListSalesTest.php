<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class ListSalesTest extends SalesTestCase
{
    public function test_sales_list_is_paginated_and_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->makeSale($user, $company);
        $this->actingAs($user)->getJson('/api/v1/sales?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
    }
}
