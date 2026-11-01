<?php

namespace Tests\Feature\Catalog;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;
use Tests\Support\FakesSriWebServices;

/** The invoice takes its products out of the cash register's warehouse; a return puts them back. */
final class KardexTest extends InvoiceFeatureTestCase
{
    use FakesSriWebServices;

    public function test_the_invoice_takes_the_units_out_of_the_cash_register_warehouse(): void
    {
        [$user, $company, $item] = $this->shop('10');
        $sale = $this->sell($company, $item, '3');

        $invoice = $this->actingAs($user)->postJson("/api/v1/sales/{$sale}/issue")->assertCreated()->json('data');

        $this->assertSame('7.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
        $movement = StockMovement::query()->where('type', StockMovement::SALE)->firstOrFail();
        $this->assertSame(['-3.000000', '7.000000', 'Factura '.$invoice['fullNumber']], [$movement->quantity, $movement->balance, $movement->note]);
        $this->getJson("/api/v1/items/{$item['publicId']}/stock-movements")->assertOk()
            ->assertJsonPath('data.0.type', 'SALE')
            ->assertJsonPath('data.0.documentNumber', $invoice['fullNumber'])
            ->assertJsonPath('data.1.type', 'INITIAL');
    }

    public function test_without_enough_stock_the_sale_waits_unless_negative_stock_is_allowed(): void
    {
        [$user, $company, $item] = $this->shop('2');
        $sale = $this->sell($company, $item, '3');

        $this->actingAs($user)->postJson("/api/v1/sales/{$sale}/issue")->assertConflict()
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('error.details.items.0.available', '2.000000');
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('cash_movements', 0);

        $this->patchJson('/api/v1/company/settings', ['allowNegativeStock' => true])->assertOk()->assertJsonPath('data.allowNegativeStock', true);
        $this->postJson("/api/v1/sales/{$sale}/issue")->assertCreated();
        $this->assertSame('-1.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
    }

    public function test_a_product_of_another_warehouse_is_never_sold_from_this_cash_register(): void
    {
        [$user, $company, $item] = $this->shop('10');
        $establishment = $company->establishments()->where('code', '001')->firstOrFail();
        $other = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $establishment->id, 'name' => 'Bodega Sur']);
        $this->actingAs($user)->patchJson("/api/v1/items/{$item['publicId']}", ['stock' => ['warehousePublicId' => $other->public_id, 'quantity' => '10']])->assertOk();
        $sale = $this->sell($company, $item, '1');

        $this->postJson("/api/v1/sales/{$sale}/issue")->assertUnprocessable()->assertJsonPath('error.code', 'ITEM_NOT_IN_WAREHOUSE');
        $this->assertSame('10.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
    }

    public function test_a_return_puts_the_goods_back_and_gives_the_cash_from_the_register(): void
    {
        [$user, $company, $item] = $this->shop('10');
        $this->enableSriTransmission($company);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'Recepcion')) {
                return Http::response($this->receptionResponse('RECIBIDA'));
            }
            $document = ElectronicDocument::query()->latest('id')->firstOrFail();
            $signed = Storage::disk('private')->get($document->artifacts()->where('type', 'SIGNED_XML')->firstOrFail()->path);

            return Http::response($this->authorizationResponse($document->access_key, 'AUTORIZADO', $signed));
        });
        $sale = $this->sell($company, $item, '3');
        $invoice = $this->actingAs($user)->postJson("/api/v1/sales/{$sale}/issue")->assertCreated()->assertJsonPath('data.status', 'AUTHORIZED')->json('data');
        $uri = "/api/v1/invoices/{$invoice['publicId']}/credit-notes";

        // A credit note that is not a return (a discount) leaves the stock as it is.
        $this->postJson($uri, ['reason' => 'Descuento posterior', 'lines' => [['lineNumber' => 1, 'quantity' => '1']]])->assertCreated()
            ->assertJsonPath('data.returnsGoods', false);
        $this->assertSame('7.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));

        $note = $this->postJson($uri, ['reason' => 'Devolución', 'lines' => [['lineNumber' => 1, 'quantity' => '2']], 'returnGoods' => true, 'refundCash' => true])
            ->assertCreated()->assertJsonPath('data.returnsGoods', true)->json('data');

        $this->assertSame('9.000000', ItemStock::query()->where('item_id', $item['id'])->value('quantity'));
        $this->assertSame('RETURN', StockMovement::query()->latest('id')->value('type'));
        $refund = CashMovement::query()->where('source', CashMovement::REFUND)->firstOrFail();
        $this->assertSame([CashMovement::OUT, '20.00', 'Devolución '.$note['fullNumber']], [$refund->type, (string) $refund->amount, $refund->concept]);
        $this->getJson('/api/v1/cash-sessions/'.CashSession::query()->value('public_id'))->assertOk()->assertJsonPath('data.balance.expected', '10.00');
    }

    /** @return array{0: User, 1: Company, 2: array{id: int, publicId: string}} a ready company with one product in the register's warehouse */
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

    /** A draft in the open cash register, paid in cash. */
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
