<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class ItemIsolationTest extends CatalogTestCase
{
    public function test_company_cannot_read_or_modify_another_company_item(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $item = $this->createItemThroughApi($userA, $this->companyHeader($companyA));

        $this->actingAs($userB)->withHeaders($this->companyHeader($companyB))->getJson('/api/v1/items/'.$item['publicId'])->assertForbidden();
        $this->patchJson('/api/v1/items/'.$item['publicId'], ['name' => 'Modificado'])->assertForbidden();
    }
}
