<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;
use Tests\Support\CreatesCompanyTaxes;

abstract class CatalogTestCase extends CompanyTestCase
{
    use CreatesCompanyTaxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSriCatalog();
    }

    protected function createItemThroughApi(User $user, string $internalCode = 'ITEM-001'): array
    {
        return $this->actingAs($user)->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => $internalCode, 'name' => 'Artículo', 'unit' => 'UND', 'salePrice' => '12.500000',
        ])->assertCreated()->json('data');
    }
}
