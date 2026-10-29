<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CompanyTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createCompany(User $owner, string $ruc): Company
    {
        return app(CreateCompanyAction::class)->execute($owner, [
            'slug' => 'empresa-'.$ruc,
            'ruc' => $ruc,
            'legal_name' => 'Empresa '.$ruc,
            'main_address' => 'Quito, Ecuador',
        ]);
    }

    /** The `stock` a new product needs: the warehouse of the main establishment and its units. */
    protected function productStock(Company $company, string $quantity = '10'): array
    {
        $establishment = $company->establishments()->where('code', '001')->first()
            ?? $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito', 'is_main' => true]);
        $warehouse = Warehouse::query()->firstOrCreate(['establishment_id' => $establishment->id], ['company_id' => $company->id, 'name' => 'Bodega Matriz']);

        return ['warehousePublicId' => $warehouse->public_id, 'quantity' => $quantity];
    }
}
