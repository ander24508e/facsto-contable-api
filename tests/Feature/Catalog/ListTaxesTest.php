<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class ListTaxesTest extends CatalogTestCase
{
    public function test_list_returns_only_current_active_global_tax_definitions(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $current = $this->createTax();
        $this->createTax(['sri_code' => '3', 'percentage_code' => '8', 'name' => 'Expirado', 'valid_until' => today()->subDay()->toDateString()]);

        $this->actingAs($user)->withHeaders($this->companyHeader($company))->getJson('/api/v1/taxes')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.publicId', $current->public_id);
    }
}
