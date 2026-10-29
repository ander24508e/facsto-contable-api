<?php

namespace Tests\Feature\Sales;

use App\Domains\Catalog\Models\Item;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\Sale;
use App\Models\User;

/** POST /sales/quote: the same math the draft and the XML use, without saving anything. */
final class SaleQuoteTest extends SalesTestCase
{
    public function test_a_price_with_iva_included_is_split_into_base_and_tax(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'LAV-1', 'type' => 'SERVICE', 'name' => 'Lavado', 'unit' => 'UND', 'sale_price' => '11.500000', 'price_includes_tax' => true, 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '4')->id, ['company_id' => $company->id]);

        $this->actingAs($user)->postJson('/api/v1/sales/quote', [
            'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '2']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '20.00']],
        ])->assertOk()
            ->assertJsonPath('data.lines.0.unitPrice', '10.000000')
            ->assertJsonPath('data.taxableBase', '20.00')
            ->assertJsonPath('data.taxTotal', '3.00')
            ->assertJsonPath('data.total', '23.00')
            ->assertJsonPath('data.taxSummary.0.percentageCode', '4')
            ->assertJsonPath('data.paymentDifference', '3.00')
            ->assertJsonPath('data.pending', ['customer', 'payment_total']);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_draft_saves_exactly_what_the_quote_showed(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'P-1', 'type' => 'PRODUCT', 'name' => 'Repuesto', 'unit' => 'UND', 'sale_price' => '3.990000', 'price_includes_tax' => true, 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '4')->id, ['company_id' => $company->id]);
        $customer = $this->finalConsumer($company->id);
        $body = ['customerPublicId' => $customer->public_id, 'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '3', 'discountAmount' => '0.50']]];

        $quote = $this->actingAs($user)->postJson('/api/v1/sales/quote', $body)->assertOk()->json('data');
        $sale = $this->actingAs($user)->postJson('/api/v1/sales', $body)->assertCreated()->json('data');

        $this->assertSame($quote['total'], $sale['total']);
        $this->assertSame($quote['taxTotal'], $sale['taxTotal']);
    }

    public function test_a_final_consumer_above_the_limit_is_pending(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'P-2', 'type' => 'PRODUCT', 'name' => 'Equipo', 'unit' => 'UND', 'sale_price' => '1000.000000', 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '0')->id, ['company_id' => $company->id]);

        $this->actingAs($user)->postJson('/api/v1/sales/quote', [
            'customerPublicId' => $this->finalConsumer($company->id)->public_id,
            'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '1000.00']],
        ])->assertOk()->assertJsonPath('data.pending', ['final_consumer_limit']);
    }

    public function test_a_payment_term_needs_its_time_unit(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->postJson('/api/v1/sales/quote', [
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '1.00', 'term' => '30']],
        ])->assertUnprocessable();
    }

    private function finalConsumer(int $companyId): Customer
    {
        return Customer::query()->create(['company_id' => $companyId, 'identification_type' => IdentificationType::FINAL_CONSUMER, 'identification' => '9999999999999', 'legal_name' => 'CONSUMIDOR FINAL', 'is_active' => true]);
    }
}
