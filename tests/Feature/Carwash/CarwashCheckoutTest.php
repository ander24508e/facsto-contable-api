<?php

namespace Tests\Feature\Carwash;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Catalog\Models\FiscalPaymentMethod;
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
        // Órdenes › Facturación «Por facturar», and Cerrar caja warns about it.
        $this->actingAs($owner)->getJson(self::API.'/work-orders?billing=TO_INVOICE')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson(self::API.'/work-orders?billing=INVOICED')->assertOk()->assertJsonCount(0, 'data');
        $session = CashSession::query()->where('company_id', $company->id)->value('public_id');
        $this->actingAs($owner)->getJson("/api/v1/cash-sessions/$session")->assertOk()
            ->assertJsonPath('data.toInvoice.count', 1)->assertJsonPath('data.toInvoice.total', '11.50');
        $movement = CashMovement::query()->where('sale_id', $sale->id)->sole();
        $this->assertSame(['11.50', null], [(string) $movement->amount, $movement->electronic_document_id]);
        // It stays in Órdenes «Cobrada · por facturar», not as a draft in Ventas.
        $this->actingAs($owner)->getJson('/api/v1/sales')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.stageCounts.draft', 0);

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

    public function test_a_mixed_payment_puts_only_its_cash_in_the_drawer(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->washing($owner, $setup);
        $card = FiscalPaymentMethod::query()->where('is_credit', false)->where('code', '19')->firstOrFail();

        $this->checkout($owner, $company, $order, '5.00', deliver: true, payments: [
            ['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '5.00'],
            ['fiscalPaymentMethodPublicId' => $card->public_id, 'amount' => '6.50'],
        ])->assertOk()->assertJsonPath('data.status', 'DELIVERED');

        $sale = Sale::query()->where('company_id', $company->id)->sole();
        $this->assertSame(2, $sale->paymentMethods()->count());
        $this->assertSame('5.00', (string) CashMovement::query()->where('sale_id', $sale->id)->value('amount'));
    }

    public function test_on_credit_the_down_payment_enters_and_the_installments_are_kept(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->washing($owner, $setup);
        $credit = FiscalPaymentMethod::query()->where('is_credit', true)->where('name', FiscalPaymentMethod::CREDIT_NAME)->value('public_id');
        $due = now((string) config('sri.timezone'))->addMonth()->toDateString();

        $this->checkout($owner, $company, $order, '11.50', deliver: false, customer: $setup['customer'], payments: [[
            'fiscalPaymentMethodPublicId' => $credit, 'amount' => '11.50', 'downPayment' => '1.50',
            'installmentFrequency' => 'MONTHLY', 'installments' => [['dueDate' => $due, 'amount' => '10.00']],
        ]])->assertOk()->assertJsonPath('data.billing.paid', true);

        $sale = Sale::query()->where('company_id', $company->id)->sole();
        $this->assertSame('María Pérez', $sale->customer_name);
        $this->assertSame('1.50', (string) CashMovement::query()->where('sale_id', $sale->id)->value('amount'));
    }

    public function test_a_separate_account_is_another_sale_with_its_own_customer_and_payment(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->washing($owner, $setup);
        $scent = $this->product($company);
        $separate = ['lines' => [['itemPublicId' => $scent->public_id, 'quantity' => '1']]];

        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/quote", ['separateLines' => $separate['lines']])->assertOk()
            ->assertJsonPath('data.total', '11.50')->assertJsonPath('data.separate.total', '2.30');
        $body = fn (string $amount) => ['customerPublicId' => $setup['customer']->public_id, 'invoice' => false, ...$separate,
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => $amount]]];

        $this->checkout($owner, $company, $order, '11.50', deliver: true, separate: $body('2.00'))->assertUnprocessable();
        $this->assertSame(0, Sale::query()->where('company_id', $company->id)->count());
        $this->checkout($owner, $company, $order, '11.50', deliver: true, separate: $body('2.30'))->assertOk()->assertJsonPath('data.status', 'DELIVERED');

        $sales = Sale::query()->where('company_id', $company->id)->orderBy('id')->get();
        $this->assertSame([['CONSUMIDOR FINAL', '11.50'], ['María Pérez', '2.30']], $sales->map(fn (Sale $sale) => [$sale->customer_name, (string) $sale->total])->all());
        $this->assertSame(['11.50', '2.30'], CashMovement::query()->orderBy('id')->pluck('amount')->map(fn ($amount) => (string) $amount)->all());
    }

    public function test_articles_added_when_scheduling_are_charged_with_the_order_or_apart(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $scent = $this->product($company);
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', [...$this->orderBody($setup), 'items' => [['itemPublicId' => $scent->public_id, 'quantity' => '2']]])->assertCreated()
            ->assertJsonPath('data.lines.1.kind', 'PRODUCT')->assertJsonPath('data.lines.1.itemPublicId', $scent->public_id)->assertJsonPath('data.lines.1.quantity', '2.000000')
            ->assertJsonPath('data.estimatedMinutes', 40)->json('data.publicId');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'ARRIVED'])->assertOk();
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'IN_PROGRESS'])->assertOk();

        // With the order: services and articles in one sale.
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/quote")->assertOk()->assertJsonPath('data.lines.1.name', 'Ambientador')->assertJsonPath('data.total', '16.10');
        // «Aparte»: the article leaves the order's sale and goes to the separate one.
        $apart = [['itemPublicId' => $scent->public_id, 'quantity' => '2']];
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/quote", ['separateLines' => $apart])->assertOk()
            ->assertJsonCount(1, 'data.lines')->assertJsonPath('data.total', '11.50')->assertJsonPath('data.separate.total', '4.60');
        $this->checkout($owner, $company, $order, '11.50', deliver: false, separate: ['customerPublicId' => $setup['customer']->public_id, 'invoice' => false, 'lines' => $apart,
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '4.60']]])->assertOk();
        $this->assertSame(['11.50', '4.60'], Sale::query()->where('company_id', $company->id)->orderBy('id')->pluck('total')->map(fn ($total) => (string) $total)->all());
    }

    private function washing(User $owner, array $setup): string
    {
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'ARRIVED'])->assertOk();
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'IN_PROGRESS'])->assertOk();

        return $order;
    }

    /**
     * @param  list<array<string, mixed>>|null  $payments
     * @param  array<string, mixed>|null  $separate
     */
    private function checkout(User $owner, Company $company, string $order, string $amount, bool $deliver, ?array $payments = null, ?Customer $customer = null, ?array $separate = null): TestResponse
    {
        $final = Customer::query()->firstOrCreate(
            ['company_id' => $company->id, 'identification_type' => IdentificationType::FINAL_CONSUMER, 'identification' => '9999999999999'],
            ['legal_name' => 'CONSUMIDOR FINAL', 'is_active' => true],
        );

        return $this->actingAs($owner)->postJson(self::API."/work-orders/$order/checkout", [
            'customerPublicId' => ($customer ?? $final)->public_id,
            'cashSessionPublicId' => CashSession::query()->where('company_id', $company->id)->value('public_id'),
            'paymentMethods' => $payments ?? [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => $amount]],
            'invoice' => false,
            'deliver' => $deliver,
            ...($separate ? ['separate' => $separate] : []),
        ]);
    }

    private function product(Company $company): Item
    {
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'AMB', 'type' => 'SERVICE', 'name' => 'Ambientador', 'unit' => 'UND', 'sale_price' => '2.00', 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '4')->id, ['company_id' => $company->id]);

        return $item;
    }
}
