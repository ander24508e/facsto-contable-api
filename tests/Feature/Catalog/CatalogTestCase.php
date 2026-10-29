<?php

namespace Tests\Feature\Catalog;

use App\Domains\Companies\Models\Company;
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
            'stock' => $this->productStock(Company::query()->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id))->firstOrFail()),
        ])->assertCreated()->json('data');
    }
}
