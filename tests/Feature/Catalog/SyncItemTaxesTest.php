<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class SyncItemTaxesTest extends CatalogTestCase
{
    public function test_item_can_only_be_assigned_a_current_active_tax(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user, $this->companyHeader($company));
        $tax = $this->createTax();

        $this->actingAs($user)->withHeaders($this->companyHeader($company))->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$tax->public_id]])
            ->assertOk()->assertJsonPath('data.taxes.0.publicId', $tax->public_id);
    }

    public function test_expired_tax_cannot_be_assigned(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user, $this->companyHeader($company));
        $tax = $this->createTax(['sri_code' => '3', 'percentage_code' => '5', 'valid_from' => today()->subYears(3)->toDateString(), 'valid_until' => today()->subDay()->toDateString()]);

        $this->actingAs($user)->withHeaders($this->companyHeader($company))->putJson('/api/v1/items/'.$item['publicId'].'/taxes', ['taxPublicIds' => [$tax->public_id]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'TAX_NOT_AVAILABLE');
    }
}
