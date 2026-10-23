<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Tax;
use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

abstract class CatalogTestCase extends CompanyTestCase
{
    protected function createTax(array $overrides = []): Tax
    {
        return Tax::query()->create($overrides + [
            'tax_type' => 'IVA', 'sri_code' => '2', 'percentage_code' => '4', 'name' => 'IVA general',
            'percentage' => '15.000000', 'valid_from' => today()->subYear()->toDateString(), 'valid_until' => null, 'is_active' => true,
        ]);
    }

    protected function createItemThroughApi(User $user, array $headers, string $internalCode = 'ITEM-001'): array
    {
        return $this->actingAs($user)->withHeaders($headers)->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => $internalCode, 'name' => 'Artículo', 'unit' => 'UND', 'salePrice' => '12.500000',
        ])->assertCreated()->json('data');
    }
}
