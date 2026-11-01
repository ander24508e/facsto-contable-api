<?php

namespace Tests\Feature\Sales;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Cash\Services\SaleCashRecorder;
use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;

/** CREDITO: a sale with a down payment and installments, collected later into its cash register (cuentas por cobrar). */
final class CreditPaymentTest extends SalesTestCase
{
    private function credit(): string
    {
        return FiscalPaymentMethod::query()->where('is_credit', true)->where('name', FiscalPaymentMethod::CREDIT_NAME)->value('public_id');
    }

    /** A sale of $10.00 on CREDITO: $4.00 down and $6.00 in two monthly installments. */
    private function creditSale(User $user, Sale $sale, array $installments, string $downPayment = '4.00'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->patchJson("/api/v1/sales/{$sale->public_id}", [
            'customerPublicId' => $sale->customer->public_id,
            'lines' => [['itemPublicId' => $sale->details()->first()->item->public_id, 'quantity' => '1', 'discountAmount' => '0.00']],
            'paymentMethods' => [[
                'fiscalPaymentMethodPublicId' => $this->credit(), 'amount' => '10.00', 'downPayment' => $downPayment,
                'installmentFrequency' => 'MONTHLY', 'creditNotes' => 'Paga los días 5', 'installments' => $installments,
            ]],
        ]);
    }

    public function test_payment_methods_are_fixed_and_include_credito(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->getJson('/api/v1/fiscal-payment-methods?per_page=100')->assertOk()
            ->assertJsonFragment(['name' => 'CREDITO', 'code' => '01', 'isCredit' => true])
            ->assertJsonCount(9, 'data');
        $this->postJson('/api/v1/fiscal-payment-methods', ['name' => 'Fiado', 'code' => '01'])->assertMethodNotAllowed();
    }

    public function test_a_sale_on_credit_keeps_its_down_payment_and_installments(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->makeCompanyReady($company);
        $sale = $this->makeSale($user, $company);
        $today = now((string) config('sri.timezone'));
        $first = $today->copy()->addMonth()->toDateString();
        $second = $today->copy()->addMonths(2)->toDateString();

        $this->creditSale($user, $sale, [])->assertUnprocessable()->assertJsonPath('error.code', 'INSTALLMENTS_INVALID');
        // The installments cover the balance after the down payment ($6.00), not the total.
        $this->creditSale($user, $sale, [['dueDate' => $first, 'amount' => '10.00']])->assertUnprocessable()->assertJsonPath('error.details.amount', '6.00');
        $this->creditSale($user, $sale, [['dueDate' => $first, 'amount' => '10.00']], '10.00')->assertUnprocessable()->assertJsonPath('error.code', 'INSTALLMENTS_INVALID');

        $this->creditSale($user, $sale, [['dueDate' => $second, 'amount' => '3.00'], ['dueDate' => $first, 'amount' => '3.00']])->assertOk()
            ->assertJsonPath('data.paymentMethods.0.isCredit', true)
            ->assertJsonPath('data.paymentMethods.0.methodCode', '01')
            ->assertJsonPath('data.paymentMethods.0.timeUnit', 'DAY')
            ->assertJsonPath('data.paymentMethods.0.downPayment', '4.00')
            ->assertJsonPath('data.paymentMethods.0.installmentFrequency', 'MONTHLY')
            ->assertJsonPath('data.paymentMethods.0.creditNotes', 'Paga los días 5')
            ->assertJsonPath('data.installments.1.dueDate', $second)
            ->assertJsonCount(2, 'data.installments');

        // Only the down payment is cash in the drawer when the invoice is issued.
        $this->assertSame('4.00', app(SaleCashRecorder::class)->cashOf($sale->fresh('paymentMethods')));
    }

    public function test_installments_are_collected_into_the_cash_register_of_the_sale(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->makeCompanyReady($company);
        $sale = $this->makeSale($user, $company);
        $today = now((string) config('sri.timezone'));
        $this->creditSale($user, $sale, [['dueDate' => $today->copy()->addMonth()->toDateString(), 'amount' => '3.00'], ['dueDate' => $today->copy()->addMonths(2)->toDateString(), 'amount' => '3.00']])->assertOk();

        // A draft is not a receivable yet.
        $this->getJson('/api/v1/receivables')->assertOk()->assertJsonPath('meta.total', 0);
        $sale->forceFill(['status' => SaleStatus::DOCUMENT_PREPARED])->save();

        $this->getJson('/api/v1/receivables?status=PENDING')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.downPayment', '4.00')->assertJsonPath('data.0.balance', '6.00')->assertJsonPath('data.0.status', 'PENDING');
        $this->postJson("/api/v1/receivables/{$sale->public_id}/payments", ['amount' => '7.00'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'CREDIT_PAYMENT_EXCEEDS_BALANCE');

        $this->postJson("/api/v1/receivables/{$sale->public_id}/payments", ['amount' => '4.00', 'notes' => 'Efectivo'])->assertCreated()
            ->assertJsonPath('data.installments.0.balance', '0.00')
            ->assertJsonPath('data.installments.1.paidAmount', '1.00')
            ->assertJsonPath('data.balance', '2.00')
            ->assertJsonPath('data.payments.0.cashRegister', '001-001');
        $session = CashSession::query()->whereKey($sale->cash_session_id)->firstOrFail();
        $this->assertSame('4.00', (string) CashMovement::query()->where('cash_session_id', $session->id)->where('source', CashMovement::CREDIT)->value('amount'));

        // With that cash register closed there is nowhere to put the money.
        $session->forceFill(['status' => CashSession::CLOSED])->save();
        $this->postJson("/api/v1/receivables/{$sale->public_id}/payments", ['amount' => '2.00'])->assertConflict()->assertJsonPath('error.code', 'CASH_SESSION_NOT_OPEN');
        // A new session of the same register receives it.
        $this->openCashRegister($company);
        $this->postJson("/api/v1/receivables/{$sale->public_id}/payments", ['amount' => '2.00'])->assertCreated()->assertJsonPath('data.status', 'PAID');
        $this->getJson('/api/v1/receivables?status=PAID')->assertOk()->assertJsonPath('meta.total', 1);

        $other = User::factory()->create();
        $this->createCompany($other, '1788888888001');
        $this->actingAs($other)->getJson("/api/v1/receivables/{$sale->public_id}")->assertNotFound();
    }

    public function test_a_company_adds_an_iva_rate_the_catalog_does_not_have(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->postJson('/api/v1/tax-definitions', ['name' => 'IVA 16%', 'percentageCode' => '11', 'percentage' => '16', 'validFrom' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.percentageCode', '11')->assertJsonPath('data.isUsable', true);
        $this->getJson('/api/v1/taxes?usable=1')->assertOk()->assertJsonFragment(['percentageCode' => '11']);
        // A code already in force is applied, not duplicated.
        $this->postJson('/api/v1/tax-definitions', ['name' => 'IVA 15', 'percentageCode' => '4', 'percentage' => '15', 'validFrom' => today()->toDateString()])
            ->assertConflict()->assertJsonPath('error.code', 'TAX_DEFINITION_EXISTS');

        $other = User::factory()->create();
        $this->createCompany($other, '1788888888001');
        $this->actingAs($other)->getJson('/api/v1/tax-definitions')->assertOk()->assertJsonMissing(['percentageCode' => '11']);
    }
}
