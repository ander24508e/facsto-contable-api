<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class ListItemsTest extends CatalogTestCase
{
    public function test_item_list_is_company_scoped_and_paginated(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->createItemThroughApi($user, $this->companyHeader($company), 'A-1');
        $this->createItemThroughApi($user, $this->companyHeader($company), 'A-2');
        $this->actingAs($user)->withHeaders($this->companyHeader($company))->getJson('/api/v1/items?per_page=1')
            ->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    }
}
