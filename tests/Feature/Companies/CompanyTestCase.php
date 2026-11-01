<?php

namespace Tests\Feature\Companies;

use App\Domains\Cash\Models\CashSession;
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
        $warehouse = Warehouse::query()->firstOrCreate(['establishment_id' => $establishment->id, 'name' => 'Bodega Matriz'], ['company_id' => $company->id]);

        return ['warehousePublicId' => $warehouse->public_id, 'quantity' => $quantity];
    }

    /**
     * Opens the cash register of the main establishment's default point (an invoice is always charged in
     * an open one), by the company's first member unless another user is given.
     */
    protected function openCashRegister(Company $company, ?User $user = null, string $amount = '0.00'): CashSession
    {
        $point = $company->emissionPoints()->where('is_default', true)->whereHas('establishment', fn ($query) => $query->where('is_main', true))->firstOrFail();
        $warehouse = Warehouse::query()->firstOrCreate(['establishment_id' => $point->establishment_id, 'name' => 'Bodega Matriz'], ['company_id' => $company->id]);

        return CashSession::query()->create([
            'company_id' => $company->id, 'emission_point_id' => $point->id, 'warehouse_id' => $warehouse->id,
            'opened_by' => ($user ?? $company->memberships()->first()->user)->id, 'opened_at' => now(),
            'opening_mode' => 'MANUAL', 'opening_amount' => $amount, 'status' => CashSession::OPEN,
        ]);
    }
}
