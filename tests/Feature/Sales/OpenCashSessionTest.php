<?php

namespace Tests\Feature\Sales;

use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class OpenCashSessionTest extends CompanyTestCase
{
    /** @return array{0: User, 1: Company, 2: string, 3: string} the owner, the company, a cash register and its warehouse */
    private function register(): array
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'is_main' => true]);
        $point = $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Caja 1', 'is_default' => true]);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $establishment->id, 'name' => 'Bodega Matriz']);

        return [$user, $company, $point->public_id, $warehouse->public_id];
    }

    public function test_a_register_opens_with_a_fixed_amount(): void
    {
        [$user, , $point, $warehouse] = $this->register();

        $this->actingAs($user)->postJson('/api/v1/cash-sessions', [
            'emissionPointPublicId' => $point, 'warehousePublicId' => $warehouse, 'mode' => 'MANUAL', 'amount' => '150.5', 'notes' => '  Fondo del día  ',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.openingAmount', '150.50')
            ->assertJsonPath('data.emissionPointCode', '001-001')
            ->assertJsonPath('data.openedBy', $user->name)
            ->assertJsonPath('data.notes', 'Fondo del día');
    }

    public function test_the_counter_total_is_computed_by_the_api(): void
    {
        [$user, , $point, $warehouse] = $this->register();

        // 2×$20 + 3×$1 + 4×$0.25 + 3×$0.10 = $44.30; the amount sent is ignored, unknown values too.
        $this->actingAs($user)->postJson('/api/v1/cash-sessions', [
            'emissionPointPublicId' => $point, 'warehousePublicId' => $warehouse, 'mode' => 'COUNTED', 'amount' => '999',
            'denominations' => ['bills' => ['20' => 2, '1' => 3, '7' => 5], 'coins' => ['0.25' => 4, '0.10' => 3, '0.05' => 0]],
        ])->assertCreated()
            ->assertJsonPath('data.mode', 'COUNTED')
            ->assertJsonPath('data.openingAmount', '44.30')
            ->assertJsonPath('data.denominations.coins', ['0.25' => 4, '0.10' => 3]);
    }

    public function test_a_register_cannot_be_opened_twice(): void
    {
        [$user, , $point, $warehouse] = $this->register();
        $open = ['emissionPointPublicId' => $point, 'warehousePublicId' => $warehouse, 'mode' => 'MANUAL', 'amount' => '20'];

        $this->actingAs($user)->postJson('/api/v1/cash-sessions', $open)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/cash-sessions', $open)->assertStatus(409)->assertJsonPath('error.code', 'CASH_SESSION_ALREADY_OPEN');
        $this->actingAs($user)->getJson('/api/v1/cash-sessions?status=OPEN&emissionPoint='.$point)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_warehouse_must_be_the_one_of_the_register_establishment(): void
    {
        [$user, $company, $point] = $this->register();
        $branch = $company->establishments()->create(['code' => '002', 'name' => 'Sucursal']);
        $other = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $branch->id, 'name' => 'Bodega Sucursal']);

        $this->actingAs($user)->postJson('/api/v1/cash-sessions', [
            'emissionPointPublicId' => $point, 'warehousePublicId' => $other->public_id, 'mode' => 'MANUAL', 'amount' => '20',
        ])->assertStatus(422)->assertJsonPath('error.code', 'WAREHOUSE_NOT_FOUND');
    }

    public function test_the_fixed_amount_is_required_and_has_at_most_two_decimals(): void
    {
        [$user, , $point, $warehouse] = $this->register();
        $open = ['emissionPointPublicId' => $point, 'warehousePublicId' => $warehouse, 'mode' => 'MANUAL'];

        $this->actingAs($user)->postJson('/api/v1/cash-sessions', $open)->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/cash-sessions', $open + ['amount' => '10.555'])->assertUnprocessable();
    }
}
