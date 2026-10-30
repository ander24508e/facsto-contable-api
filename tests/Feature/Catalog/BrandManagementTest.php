<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Brand;
use App\Models\User;

final class BrandManagementTest extends CatalogTestCase
{
    public function test_company_member_can_create_update_filter_and_deactivate_brands(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $brand = $this->actingAs($user)->postJson('/api/v1/brands', ['name' => 'Condore', 'description' => 'Marca propia'])
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('brands', ['public_id' => $brand['publicId'], 'name' => 'Condore']);

        $this->patchJson('/api/v1/brands/'.$brand['publicId'], ['name' => 'Condore Pro'])
            ->assertOk()->assertJsonPath('data.name', 'Condore Pro');
        $this->postJson('/api/v1/brands/'.$brand['publicId'].'/deactivate')
            ->assertOk()->assertJsonPath('data.isActive', false);
        $this->getJson('/api/v1/brands?status=INACTIVE')
            ->assertOk()->assertJsonPath('data.0.publicId', $brand['publicId']);
    }

    public function test_brand_name_is_unique_per_company_without_case_sensitivity(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $this->actingAs($user)->postJson('/api/v1/brands', ['name' => 'Condore'])->assertCreated();
        $this->postJson('/api/v1/brands', ['name' => 'condore'])
            ->assertStatus(409)->assertJsonPath('error.code', 'BRAND_DUPLICATE');
    }

    public function test_brand_cannot_be_deleted_while_assigned_to_an_item(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $brand = $this->actingAs($user)->postJson('/api/v1/brands', ['name' => 'Marca usada'])->assertCreated()->json('data');
        $this->postJson('/api/v1/items', [
            'type' => 'SERVICE', 'internalCode' => 'SERVICE-001', 'name' => 'Servicio', 'unit' => 'SERVICIO',
            'salePrice' => '1.000000', 'brandPublicId' => $brand['publicId'],
        ])->assertCreated();

        $this->deleteJson('/api/v1/brands/'.$brand['publicId'])
            ->assertStatus(409)->assertJsonPath('error.code', 'BRAND_IN_USE');
        $this->assertDatabaseHas('brands', ['public_id' => $brand['publicId']]);
    }
}
