<?php

namespace Tests\Feature\Catalog;

use App\Domains\Cash\Models\CashSession;
use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** Stock: only products; entries, exits and mass counts; proformas reserve, or take with an advance or on credit. */
final class StockTest extends InvoiceFeatureTestCase
{
    public function test_entries_exits_and_a_mass_count_move_only_products(): void
    {
        [$user, $company, $item] = $this->shop('10');
        $service = $this->postJson('/api/v1/items', ['type' => 'SERVICE', 'internalCode' => 'LAV', 'name' => 'Lavado', 'unit' => 'UND', 'salePrice' => '5'])->assertCreated()->json('data.publicId');

        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'ENTRY', 'quantity' => '5', 'reason' => 'DONATION'])
            ->assertCreated()->assertJsonPath('data.balance', '15.000000');
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'EXIT', 'quantity' => '20', 'reason' => 'DAMAGE'])
            ->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'EXIT', 'quantity' => '3', 'reason' => 'DAMAGE', 'note' => 'Botellas rotas'])->assertCreated();
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $service, 'type' => 'ENTRY', 'quantity' => '1', 'reason' => 'OTHER'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'STOCK_ITEM_NOT_PRODUCT');

        // Mass count: 12 stay 12 (no movement), so only the written difference counts.
        $this->postJson('/api/v1/stock/adjustments', ['note' => 'Conteo de noviembre', 'lines' => [['itemPublicId' => $item['publicId'], 'counted' => '9']]])
            ->assertCreated()->assertJsonPath('data.number', 'AJ-000001')->assertJsonPath('data.changed', 1);
        $this->assertSame('9.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));

        // Two decimals at most; Movimientos filters by Ingreso / Egreso and by warehouse.
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'ENTRY', 'quantity' => '1.255', 'reason' => 'OTHER'])->assertUnprocessable();
        $this->getJson('/api/v1/stock/movements?direction=IN')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/stock/movements?direction=OUT')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/stock/movements?warehousePublicId=00000000-0000-4000-8000-000000000000')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/stock')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '9.000000')->assertJsonPath('meta.lowThreshold', 5);
        $this->getJson('/api/v1/stock/movements')->assertOk()
            ->assertJsonPath('data.0.type', 'ADJUSTMENT')
            ->assertJsonPath('data.0.document.number', 'AJ-000001')
            ->assertJsonPath('data.1.reason', 'DAMAGE');
    }

    public function test_a_proforma_reserves_in_yellow_and_a_sale_may_not_take_the_reserved_units(): void
    {
        [$user, $company, $item] = $this->shop('3');
        $proforma = $this->proforma($item, '2');
        $this->assertSame('3.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
        $this->getJson("/api/v1/items/{$item['publicId']}")->assertOk()->assertJsonPath('data.stock.reserved', '2.000000')->assertJsonPath('data.stock.available', '1.000000');
        $this->getJson("/api/v1/proformas/{$proforma}")->assertOk()->assertJsonPath('data.lines.0.availability.state', 'FREE');

        // 1 free unit: a sale of 2 would take a reserved one.
        $sale = $this->sell($company, $item, '2');
        $this->postJson("/api/v1/sales/{$sale}/issue")->assertConflict()->assertJsonPath('error.code', 'STOCK_RESERVED');
        $this->assertDatabaseCount('electronic_documents', 0);

        // Rejecting frees the reservation.
        $this->postJson("/api/v1/proformas/{$proforma}/reject", ['reason' => 'Precio'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->getJson("/api/v1/items/{$item['publicId']}")->assertJsonPath('data.stock.reserved', '0');
        $this->patchJson("/api/v1/proformas/{$proforma}", $this->proformaBody($item, '1'))->assertConflict()->assertJsonPath('error.code', 'PROFORMA_NOT_OPEN');
    }

    public function test_an_advance_or_credit_takes_the_stock_and_rejecting_gives_it_back(): void
    {
        [$user, $company, $item] = $this->shop('5');
        $cash = FiscalPaymentMethod::query()->where('is_credit', false)->where('code', '01')->value('public_id');
        $proforma = $this->postJson('/api/v1/proformas', [...$this->proformaBody($item, '2'), 'advanceAmount' => '5.00', 'advancePaymentMethodPublicId' => $cash])
            ->assertCreated()->assertJsonPath('data.stockCommitted', true)->assertJsonPath('data.advanceAmount', '5.00')->json('data.publicId');
        $this->assertSame('3.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
        $this->assertSame('PROFORMA', StockMovement::query()->latest('id')->value('type'));

        // Another proforma on credit can only take what is free.
        $this->postJson('/api/v1/proformas', [...$this->proformaBody($item, '4'), 'onCredit' => true])->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->deleteJson("/api/v1/proformas/{$proforma}")->assertConflict()->assertJsonPath('error.code', 'PROFORMA_HAS_STOCK_HISTORY');

        $this->postJson("/api/v1/proformas/{$proforma}/reject", ['reason' => 'Compró en otro lado'])->assertOk()->assertJsonPath('data.stockCommitted', false);
        $this->assertSame('5.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
        $this->getJson('/api/v1/proformas?status=REJECTED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.conversion.closed', 1);
    }

    public function test_an_expired_proforma_frees_its_reservation_until_it_is_renewed(): void
    {
        [$user, $company, $item] = $this->shop('5');
        $proforma = $this->postJson('/api/v1/proformas', [...$this->proformaBody($item, '4'), 'issueDate' => today()->subDays(10)->toDateString(), 'expirationDate' => today()->subDay()->toDateString()])
            ->assertCreated()->assertJsonPath('data.status', 'EXPIRED')->json('data.publicId');
        $this->getJson("/api/v1/items/{$item['publicId']}")->assertJsonPath('data.stock.reserved', '0');

        $this->postJson("/api/v1/proformas/{$proforma}/renew", ['days' => 15])->assertOk()
            ->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.expirationDate', today()->addDays(15)->toDateString());
        $this->getJson("/api/v1/items/{$item['publicId']}")->assertJsonPath('data.stock.reserved', '4.000000');
        $this->postJson("/api/v1/proformas/{$proforma}/accept")->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
    }

    public function test_stock_needs_its_own_permissions(): void
    {
        [$user, $company, $item] = $this->shop('5');
        $seller = $this->memberAs($company, ['inventory.view']);
        $counter = $this->memberAs($company, ['stock.view', 'stock.move']);

        $this->actingAs($seller)->getJson('/api/v1/stock')->assertForbidden();
        $this->actingAs($counter)->getJson('/api/v1/stock')->assertOk();
        $this->actingAs($counter)->postJson('/api/v1/stock/adjustments', ['lines' => [['itemPublicId' => $item['publicId'], 'counted' => '1']]])->assertForbidden();
    }

    /** @return array{0: User, 1: Company, 2: array{id: int, publicId: string}} */
    public function test_a_transfer_sends_the_units_written_to_another_warehouse(): void
    {
        [$user, $company, $item] = $this->shop('8');
        $origin = Warehouse::query()->whereKey(ItemStock::query()->where('item_id', $item['id'])->value('warehouse_id'))->firstOrFail();
        $other = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $origin->establishment_id, 'name' => 'Bodega Norte']);

        // An exit only from a warehouse where it has units; an entry may open a new one.
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'EXIT', 'quantity' => '1', 'reason' => 'DAMAGE', 'warehousePublicId' => $other->public_id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ITEM_NOT_IN_WAREHOUSE');

        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'TRANSFER', 'quantity' => '9', 'warehousePublicId' => $origin->public_id, 'toWarehousePublicId' => $other->public_id])
            ->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'TRANSFER', 'quantity' => '3', 'warehousePublicId' => $origin->public_id, 'toWarehousePublicId' => $other->public_id])
            ->assertCreated()->assertJsonPath('data.balance', '3.000000');
        $this->postJson('/api/v1/stock/movements', ['itemPublicId' => $item['publicId'], 'type' => 'TRANSFER', 'quantity' => '1', 'warehousePublicId' => $other->public_id, 'toWarehousePublicId' => $other->public_id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ITEM_NOT_IN_WAREHOUSE');

        $this->assertSame('5.000000', ItemStock::query()->where('item_id', $item['id'])->where('warehouse_id', $origin->id)->value('quantity'));
        $this->assertSame('3.000000', ItemStock::query()->where('item_id', $item['id'])->where('warehouse_id', $other->id)->value('quantity'));
        $this->getJson('/api/v1/stock/movements?warehousePublicId='.$other->public_id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'TRANSFER')->assertJsonPath('data.0.quantity', '3.000000');
        $this->getJson('/api/v1/stock')->assertOk()->assertJsonPath('data.0.quantity', '8.000000')->assertJsonCount(2, 'data.0.warehouses')
            ->assertJsonPath('data.0.warehouse.name', 'Bodega Matriz');

        // The mass count is per warehouse; the sale takes from the cash register's warehouse (the origin).
        $this->postJson('/api/v1/stock/adjustments', ['lines' => [['itemPublicId' => $item['publicId'], 'warehousePublicId' => $other->public_id, 'counted' => '2']]])->assertCreated();
        $this->assertSame('2.000000', ItemStock::query()->where('item_id', $item['id'])->where('warehouse_id', $other->id)->value('quantity'));
        $this->assertSame('5.000000', ItemStock::query()->where('item_id', $item['id'])->where('warehouse_id', $origin->id)->value('quantity'));
        $this->getJson('/api/v1/items/'.$item['publicId'])->assertOk()->assertJsonPath('data.stock.quantity', '7.000000');
    }

    private function shop(string $stock): array
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $item = $this->actingAs($user)->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '10', 'stock' => $this->productStock($company, $stock),
        ])->assertCreated()->json('data.publicId');
        $this->putJson("/api/v1/items/{$item}/taxes", ['taxPublicIds' => [$this->companyIva($company, '0')->public_id]])->assertOk();

        return [$user, $company, ['id' => Item::query()->where('public_id', $item)->value('id'), 'publicId' => $item]];
    }

    /** @param array{publicId: string} $item */
    private function proformaBody(array $item, string $quantity): array
    {
        return [
            'issueDate' => today()->toDateString(),
            'expirationDate' => today()->addDays(7)->toDateString(),
            'lines' => [['itemPublicId' => $item['publicId'], 'quantity' => $quantity]],
        ];
    }

    private function proforma(array $item, string $quantity): string
    {
        return $this->postJson('/api/v1/proformas', $this->proformaBody($item, $quantity))->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data.publicId');
    }

    private function sell(Company $company, array $item, string $quantity): string
    {
        $customer = Customer::query()->firstOrCreate(
            ['company_id' => $company->id, 'identification_type' => IdentificationType::CEDULA, 'identification' => '0102030405'],
            ['legal_name' => 'Cliente de prueba', 'is_active' => true],
        );

        return $this->postJson('/api/v1/sales', [
            'cashSessionPublicId' => CashSession::query()->where('status', CashSession::OPEN)->value('public_id'),
            'customerPublicId' => $customer->public_id,
            'lines' => [['itemPublicId' => $item['publicId'], 'quantity' => $quantity]],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => bcmul($quantity, '10', 2)]],
        ])->assertCreated()->json('data.publicId');
    }
}
