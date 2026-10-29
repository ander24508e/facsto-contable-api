<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class DeleteCategoryTest extends CatalogTestCase
{
    public function test_a_category_without_items_is_deleted_with_its_default_taxes(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $category = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Bebidas'])->assertCreated()->json('data');
        $this->putJson('/api/v1/categories/'.$category['publicId'].'/taxes', ['taxPublicIds' => [$this->companyIva($company, '4')->public_id]])->assertOk();

        $this->deleteJson('/api/v1/categories/'.$category['publicId'])->assertNoContent();

        $this->assertDatabaseMissing('categories', ['public_id' => $category['publicId']]);
        $this->assertDatabaseCount('category_taxes', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.deleted', 'resource_public_id' => $category['publicId']]);
    }

    public function test_a_category_with_items_is_kept_and_only_deactivation_is_offered(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $category = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Servicios'])->assertCreated()->json('data');
        $this->postJson('/api/v1/items', [
            'type' => 'SERVICE', 'internalCode' => 'SERV-1', 'name' => 'Servicio', 'unit' => 'UND', 'salePrice' => '10.000000', 'categoryPublicId' => $category['publicId'],
        ])->assertCreated();

        $this->deleteJson('/api/v1/categories/'.$category['publicId'])->assertStatus(409)->assertJsonPath('error.code', 'CATEGORY_IN_USE');
        $this->assertDatabaseHas('categories', ['public_id' => $category['publicId'], 'is_active' => true]);
    }
}
