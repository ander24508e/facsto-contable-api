<?php

namespace Tests\Feature\Carwash;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** The checkout window of an order: charge it in the open cash register, with or without invoice, and deliver it. */
final class CarwashCheckoutTest extends CarwashTestCase
{
    public function test_the_quote_adds_products_to_the_order_services(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->washing($owner, $setup);
        $scent = $this->product($company);

        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/quote", ['extraLines' => [['itemPublicId' => $scent->public_id, 'quantity' => '2']]])->assertOk()
            ->assertJsonPath('data.lines.0.name', 'Lavado completo')->assertJsonPath('data.lines.1.name', 'Ambientador')
            ->assertJsonPath('data.subtotal', '14.00')->assertJsonPath('data.tax', '2.10')->assertJsonPath('data.total', '16.10');
    }

    public function test_without_invoice_the_sale_stays_a_draft_its_cash_enters_and_the_vehicle_is_delivered(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->washing($owner, $setup);

        // The payment must cover the total.
        $this->checkout($owner, $company, $order, '5.00', deliver: true)->assertUnprocessable();
        $this->checkout($owner, $company, $order, '11.50', deliver: true)->assertOk()
            ->assertJsonPath('data.status', 'DELIVERED')->assertJsonPath('data.billing.status', 'DRAFT')->assertJsonPath('data.billing.paid', true);

        $sale = Sale::query()->where('company_id', $company->id)->sole();
        $this->assertSame(SaleStatus::DRAFT, $sale->status);
        $this->assertSame('CONSUMIDOR FINAL', $sale->customer_name);
        $movement = CashMovement::query()->where('sale_id', $sale->id)->sole();
        $this->assertSame(['11.50', null], [(string) $movement->amount, $movement->electronic_document_id]);

        // Cancelled in Ventas before its invoice: the money leaves the drawer.
        $this->actingAs($owner)->postJson("/api/v1/sales/{$sale->public_id}/cancel")->assertOk();
        $this->assertSame(0, CashMovement::query()->where('sale_id', $sale->id)->count());
    }

    public function test_confirm_without_delivering_keeps_the_order_and_it_is_delivered_later(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->washing($owner, $setup);

        $this->checkout($owner, $company, $order, '11.50', deliver: false)->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.billing.paid', true);
        // Charged again (a correction): the same draft and the same cash movement.
        $this->checkout($owner, $company, $order, '11.50', deliver: false)->assertOk();
        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, CashMovement::query()->where('source', CashMovement::SALE)->count());

        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'READY'])->assertOk();
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'DELIVERED'])->assertOk()->assertJsonPath('data.status', 'DELIVERED');
    }

    private function washing(User $owner, array $setup): string
    {
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'ARRIVED'])->assertOk();
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'IN_PROGRESS'])->assertOk();

        return $order;
    }

    private function checkout(User $owner, Company $company, string $order, string $amount, bool $deliver): TestResponse
    {
        $final = Customer::query()->firstOrCreate(
            ['company_id' => $company->id, 'identification_type' => IdentificationType::FINAL_CONSUMER, 'identification' => '9999999999999'],
            ['legal_name' => 'CONSUMIDOR FINAL', 'is_active' => true],
        );

        return $this->actingAs($owner)->postJson(self::API."/work-orders/$order/checkout", [
            'customerPublicId' => $final->public_id,
            'cashSessionPublicId' => CashSession::query()->where('company_id', $company->id)->value('public_id'),
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => $amount]],
            'invoice' => false,
            'deliver' => $deliver,
        ]);
    }

    private function product(Company $company): Item
    {
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'AMB', 'type' => 'SERVICE', 'name' => 'Ambientador', 'unit' => 'UND', 'sale_price' => '2.00', 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '4')->id, ['company_id' => $company->id]);

        return $item;
    }
}
