<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** Reglas de la ficha técnica SRI offline v2.34 que se validan antes de numerar. */
final class InvoiceSriFichaRulesTest extends InvoiceFeatureTestCase
{
    public function test_final_consumer_invoice_above_fifty_dollars_is_blocked(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->finalConsumerSale($user, $this->makeSale($user, $company), '6');

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SALE_NOT_READY_FOR_INVOICE')
            ->assertJsonPath('error.details.pending', ['final_consumer_limit']);
    }

    public function test_final_consumer_invoice_of_exactly_fifty_dollars_is_allowed(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->finalConsumerSale($user, $this->makeSale($user, $company), '5');

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated();
    }

    public function test_a_company_that_will_transmit_cannot_number_without_the_provider_ruc(): void
    {
        config(['sri.software_provider.ruc' => null]);
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $company->fiscalSettings->forceFill(['sri_submission_enabled' => true])->save();
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertConflict()
            ->assertJsonPath('error.code', 'SRI_PROVIDER_NOT_CONFIGURED');
        $this->assertDatabaseCount('electronic_documents', 0);
    }

    public function test_an_invalid_provider_ruc_is_never_frozen_into_a_document(): void
    {
        config(['sri.software_provider.ruc' => '1234567890001']);
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertConflict()
            ->assertJsonPath('error.code', 'SRI_PROVIDER_NOT_CONFIGURED');
    }

    public function test_item_codes_follow_the_twenty_five_character_xml_limit(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => str_repeat('A', 26), 'name' => 'Artículo', 'unit' => 'UND', 'salePrice' => '1.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['internalCode'], 'error.details.errors');
    }

    private function finalConsumerSale(User $user, Sale $sale, string $quantity): Sale
    {
        $customer = Customer::query()->create([
            'company_id' => $sale->company_id, 'identification_type' => IdentificationType::FINAL_CONSUMER,
            'identification' => '9999999999999', 'legal_name' => 'Consumidor final', 'is_active' => true,
        ]);
        $method = FiscalPaymentMethod::query()->create(['code' => '01', 'name' => 'Sin utilización del sistema financiero', 'valid_from' => today()->subDay(), 'is_active' => true]);
        $amount = bcmul($quantity, '10', 2);

        $this->actingAs($user)->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $customer->public_id,
            'lines' => [['itemPublicId' => $sale->details()->firstOrFail()->item->public_id, 'quantity' => $quantity]],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $method->public_id, 'amount' => $amount]],
        ])->assertOk();

        return $sale->refresh();
    }
}
