<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class CreateCategoryTest extends CatalogTestCase
{
    public function test_company_member_can_create_update_and_deactivate_a_category(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $headers = $this->companyHeader($company);

        $created = $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/categories', [
            'name' => 'Bebidas',
            'description' => 'Productos líquidos',
            'company_id' => 9999,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('categories', ['public_id' => $created['publicId'], 'company_id' => $company->id, 'name' => 'Bebidas']);
        $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/categories/'.$created['publicId'], ['name' => 'Bebidas frías'])->assertOk()->assertJsonPath('data.name', 'Bebidas frías');
        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/categories/'.$created['publicId'].'/deactivate')->assertOk()->assertJsonPath('data.isActive', false);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/categories?status=INACTIVE')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_category_name_is_unique_within_company(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $headers = $this->companyHeader($company);
        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/categories', ['name' => 'Servicios'])->assertCreated();

        $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/categories', ['name' => 'servicios'])->assertStatus(409)->assertJsonPath('error.code', 'CATEGORY_DUPLICATE');
    }
}
