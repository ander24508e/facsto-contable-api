<?php

namespace Tests\Unit\Catalog;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Taxes\Services\ItemTaxService;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanyTaxes;
use Tests\TestCase;

final class ItemTaxServiceTest extends TestCase
{
    use CreatesCompanyTaxes;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSriCatalog();
        $owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($owner, ['slug' => 'empresa-unit', 'ruc' => '1799999999001', 'legal_name' => 'Empresa', 'main_address' => 'Quito']);
        app(CompanyContext::class)->set($this->company);
    }

    public function test_item_tax_assignment_is_synchronized_to_one_iva_plus_other_taxes(): void
    {
        $item = $this->item();
        $iva = $this->companyIva($this->company, '4');
        $ice = $this->customCompanyTax($this->company, ['tax_type' => 'ICE', 'sri_tax_code' => '3', 'sri_percentage_code' => '3092', 'name' => 'ICE prueba', 'fixed_percentage' => '0.000000']);

        app(ItemTaxService::class)->sync($item, [$iva->public_id, $ice->public_id]);

        $this->assertEqualsCanonicalizing([$iva->id, $ice->id], $item->taxes()->pluck('company_taxes.id')->all());
    }

    public function test_an_item_without_iva_is_rejected(): void
    {
        $item = $this->item();
        $ice = $this->customCompanyTax($this->company, ['tax_type' => 'ICE', 'sri_tax_code' => '3', 'sri_percentage_code' => '3092', 'name' => 'ICE prueba', 'fixed_percentage' => '0.000000']);

        try {
            app(ItemTaxService::class)->sync($item, [$ice->public_id]);
            $this->fail('An item without IVA must be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('ITEM_IVA_REQUIRED', $exception->errorCode->value);
        }
        $this->assertSame(0, $item->taxes()->count());
    }

    public function test_an_item_with_two_iva_rates_is_rejected(): void
    {
        $this->expectException(ApiException::class);
        app(ItemTaxService::class)->sync($this->item(), [$this->companyIva($this->company, '0')->public_id, $this->companyIva($this->company, '4')->public_id]);
    }

    private function item(): Item
    {
        return $this->company->items()->create(['type' => 'PRODUCT', 'internal_code' => 'A-1', 'name' => 'Producto', 'unit' => 'UND', 'sale_price' => '1.230000']);
    }
}
