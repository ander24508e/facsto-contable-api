<?php

namespace Tests\Unit\Catalog;

use App\Domains\Catalog\Models\Tax;
use App\Domains\Catalog\Services\ItemTaxService;
use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ItemTaxServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_tax_assignment_is_synchronized_to_tax_ids(): void
    {
        $owner = User::factory()->create();
        $company = app(CreateCompanyAction::class)->execute($owner, ['ruc' => '1799999999001', 'legal_name' => 'Empresa', 'main_address' => 'Quito']);
        app(CompanyContext::class)->set($company);
        $item = $company->items()->create(['type' => 'PRODUCT', 'internal_code' => 'A-1', 'name' => 'Producto', 'unit' => 'UND', 'sale_price' => '1.230000']);
        $taxes = collect([1, 2])->map(fn ($code) => Tax::query()->create([
            'tax_type' => 'IVA', 'sri_code' => '2', 'percentage_code' => (string) $code, 'name' => 'IVA '.$code,
            'percentage' => '0.000000', 'valid_from' => today()->subDay(), 'is_active' => true,
        ]));

        app(ItemTaxService::class)->sync($item, $taxes->pluck('public_id')->all());
        $this->assertEqualsCanonicalizing($taxes->pluck('id')->all(), $item->taxes()->pluck('taxes.id')->all());
    }
}
