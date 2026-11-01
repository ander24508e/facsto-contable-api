<?php

namespace Tests\Feature\Sales;

use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Models\User;

/** A proforma is an offer: its own numbering, the sale's math, and no effect on anything else. */
final class ProformaTest extends SalesTestCase
{
    public function test_a_proforma_is_priced_like_a_sale_but_touches_nothing_else(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $stock = $this->productStock($company, '5');
        $item = $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '10', 'stock' => $stock])
            ->assertCreated()->json('data.publicId');
        $this->putJson("/api/v1/items/{$item}/taxes", ['taxPublicIds' => [$this->companyIva($company, '4')->public_id]])->assertOk();
        $customer = Customer::query()->create(['company_id' => $company->id, 'identification_type' => IdentificationType::CEDULA, 'identification' => '0102030405', 'legal_name' => 'Ana Pérez', 'is_active' => true]);

        // More units than the warehouse has: an offer never reserves nor checks stock.
        $proforma = $this->postJson('/api/v1/proformas', [
            'customerPublicId' => $customer->public_id, 'warehousePublicId' => $stock['warehousePublicId'],
            'lines' => [['itemPublicId' => $item, 'quantity' => '8']],
        ])->assertCreated()
            ->assertJsonPath('data.number', 'PRO-000001')
            ->assertJsonPath('data.total', '92.00')
            ->assertJsonPath('data.customer.name', 'Ana Pérez')
            ->json('data');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('5.000000', ItemStock::query()->value('quantity'));
        $this->assertSame(1, StockMovement::query()->count());

        $this->patchJson("/api/v1/proformas/{$proforma['publicId']}", ['customerPublicId' => $customer->public_id, 'lines' => [['itemPublicId' => $item, 'quantity' => '1']], 'notes' => 'Válida 15 días'])
            ->assertOk()->assertJsonPath('data.total', '11.50')->assertJsonPath('data.lines.0.quantity', '1.000000');
        $this->postJson('/api/v1/proformas', ['lines' => [['itemPublicId' => $item, 'quantity' => '2']]])->assertCreated()->assertJsonPath('data.number', 'PRO-000002');
        $this->getJson('/api/v1/proformas?search=ana')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/v1/proformas/{$proforma['publicId']}")->assertNoContent();
        $this->getJson('/api/v1/proformas')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_proforma_of_another_company_is_not_found(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $item = $this->makeSale($owner, $company)->details()->first()->item;
        $proforma = $this->actingAs($owner)->postJson('/api/v1/proformas', ['lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1']]])->assertCreated()->json('data.publicId');

        $intruder = User::factory()->create();
        $this->createCompany($intruder, '1788888888001');
        $this->actingAs($intruder)->getJson("/api/v1/proformas/{$proforma}")->assertNotFound()->assertJsonPath('error.code', 'PROFORMA_NOT_FOUND');
        $this->deleteJson("/api/v1/proformas/{$proforma}")->assertNotFound();
    }
}
