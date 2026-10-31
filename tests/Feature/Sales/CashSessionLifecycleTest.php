<?php

namespace Tests\Feature\Sales;

use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class CashSessionLifecycleTest extends CompanyTestCase
{
    /** @return array{0: User, 1: string, 2: string} the owner, an open session (opened with $50.00) and its cash register */
    private function openRegister(): array
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'is_main' => true]);
        $point = $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Caja 1', 'is_default' => true]);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $establishment->id, 'name' => 'Bodega Matriz']);
        $session = $this->actingAs($user)->postJson('/api/v1/cash-sessions', [
            'emissionPointPublicId' => $point->public_id, 'warehousePublicId' => $warehouse->public_id, 'mode' => 'MANUAL', 'amount' => '50',
        ])->assertCreated()->json('data.publicId');

        return [$user, $session, $point->public_id];
    }

    public function test_money_in_and_out_change_what_the_drawer_should_hold(): void
    {
        [$user, $session] = $this->openRegister();

        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/movements", ['type' => 'IN', 'amount' => '20.25', 'concept' => 'Cambio del banco'])->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/movements", ['type' => 'OUT', 'amount' => '10', 'concept' => 'Pago a proveedor'])->assertCreated();

        $this->actingAs($user)->getJson("/api/v1/cash-sessions/{$session}")->assertOk()
            ->assertJsonPath('data.balance', ['opening' => '50.00', 'in' => '20.25', 'out' => '10.00', 'expected' => '60.25'])
            ->assertJsonCount(2, 'data.movements')
            ->assertJsonPath('data.movements.1.concept', 'Pago a proveedor')
            ->assertJsonPath('data.movements.1.createdBy', $user->name);
    }

    public function test_money_out_cannot_leave_the_drawer_below_zero(): void
    {
        [$user, $session] = $this->openRegister();

        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/movements", ['type' => 'OUT', 'amount' => '50.01', 'concept' => 'Retiro'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_INSUFFICIENT');
        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/movements", ['type' => 'IN', 'amount' => '0', 'concept' => 'Nada'])
            ->assertUnprocessable();
    }

    public function test_closing_counts_the_money_and_keeps_the_difference(): void
    {
        [$user, $session, $point] = $this->openRegister();
        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/movements", ['type' => 'IN', 'amount' => '15', 'concept' => 'Ingreso extra'])->assertCreated();

        // Expected $65.00; counted 3×$20 + 4×$1 + 2×$0.25 = $64.50 → $0.50 short.
        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/close", [
            'mode' => 'COUNTED', 'denominations' => ['bills' => ['20' => 3, '1' => 4], 'coins' => ['0.25' => 2]], 'notes' => 'Faltan 50 centavos',
        ])->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.expectedAmount', '65.00')
            ->assertJsonPath('data.closingAmount', '64.50')
            ->assertJsonPath('data.difference', '-0.50')
            ->assertJsonPath('data.closedBy', $user->name);

        // Closed: no second closing, no more movements; the register can be opened again.
        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/close", ['mode' => 'MANUAL', 'amount' => '64.50'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_SESSION_NOT_OPEN');
        $this->actingAs($user)->postJson("/api/v1/cash-sessions/{$session}/movements", ['type' => 'IN', 'amount' => '1', 'concept' => 'Tarde'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_SESSION_NOT_OPEN');
        $this->actingAs($user)->getJson('/api/v1/cash-sessions?status=CLOSED&emissionPoint='.$point)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_company_cannot_see_or_close_the_session(): void
    {
        [, $session] = $this->openRegister();
        $stranger = User::factory()->create();
        $this->createCompany($stranger, '1799999999002');

        $this->actingAs($stranger)->getJson("/api/v1/cash-sessions/{$session}")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/v1/cash-sessions/{$session}/close", ['mode' => 'MANUAL', 'amount' => '0'])->assertNotFound();
    }
}
