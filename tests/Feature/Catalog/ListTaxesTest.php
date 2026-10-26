<?php

namespace Tests\Feature\Catalog;

use App\Models\User;

final class ListTaxesTest extends CatalogTestCase
{
    public function test_every_company_receives_the_current_official_iva_rates(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $codes = collect($this->actingAs($user)->getJson('/api/v1/taxes?usable=1')->assertOk()->json('data'))
            ->pluck('percentageCode')->sort()->values()->all();

        // Historical 12 % and 14 % stay in the official catalog but are never enabled for products.
        $this->assertSame(['0', '4', '5', '6', '7'], $codes);
    }

    public function test_company_taxes_are_isolated_between_companies(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createCompany($owner, '1799999999001');
        $otherCompany = $this->createCompany($other, '1790016919001');
        $foreign = $this->companyIva($otherCompany, '4');

        $publicIds = collect($this->actingAs($owner)->getJson('/api/v1/taxes')->assertOk()->json('data'))->pluck('publicId');

        $this->assertNotContains($foreign->public_id, $publicIds);
    }

    public function test_official_definitions_and_fiscal_rules_are_read_only_for_companies(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->getJson('/api/v1/tax-definitions')->assertOk()->assertJsonCount(7, 'data');
        $this->actingAs($user)->getJson('/api/v1/fiscal-rules')->assertOk()
            ->assertJsonFragment(['code' => 'FINAL_CONSUMER_MAX_TOTAL', 'value' => '50.00'])
            ->assertJsonFragment(['code' => 'MAX_TIP_PERCENTAGE', 'value' => '10.00']);
        $this->actingAs($user)->postJson('/api/v1/fiscal-rules', ['code' => 'MAX_TIP_PERCENTAGE', 'value' => '15'])->assertStatus(405);
    }
}
