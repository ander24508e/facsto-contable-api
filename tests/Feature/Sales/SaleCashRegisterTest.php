<?php

namespace Tests\Feature\Sales;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Sales\Models\Sale;
use App\Models\User;

/** Every invoice is charged in an open cash register: it decides the point and receives the cash. */
final class SaleCashRegisterTest extends InvoiceFeatureTestCase
{
    public function test_without_an_open_cash_register_the_sale_is_not_invoiced(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        CashSession::query()->update(['status' => CashSession::CLOSED]);
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson("/api/v1/sales/{$sale->public_id}/issue")
            ->assertConflict()->assertJsonPath('error.code', 'CASH_SESSION_REQUIRED');
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertSame('DRAFT', $sale->refresh()->status->value);
    }

    public function test_the_invoice_comes_from_the_cash_register_point_and_its_cash_enters_the_drawer(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $branch = $company->establishments()->create(['code' => '002', 'name' => 'Norte', 'address' => 'Quito norte', 'is_active' => true]);
        $point = $branch->emissionPoints()->create(['company_id' => $company->id, 'code' => '003', 'name' => 'Caja Norte', 'is_active' => true]);
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $branch->id, 'name' => 'Bodega Norte']);
        $session = $this->actingAs($user)->postJson('/api/v1/cash-sessions', [
            'emissionPointPublicId' => $point->public_id, 'warehousePublicId' => $warehouse->public_id, 'mode' => 'MANUAL', 'amount' => '20.00',
        ])->assertCreated()->json('data.publicId');
        $sale = $this->makeSale($user, $company);

        $this->postJson("/api/v1/sales/{$sale->public_id}/issue", ['cashSessionPublicId' => $session])->assertCreated()
            ->assertJsonPath('data.fullNumber', '002-003-000000001');

        $this->assertSame($session, $sale->refresh()->cashSession->public_id);
        $movement = CashMovement::query()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame([CashMovement::IN, CashMovement::SALE, '10.00', 'Venta 002-003-000000001'], [$movement->type, $movement->source, (string) $movement->amount, $movement->concept]);
        $this->getJson("/api/v1/cash-sessions/{$session}")->assertOk()
            ->assertJsonPath('data.balance.expected', '30.00')
            ->assertJsonPath('data.sales.count', 1)
            ->assertJsonPath('data.sales.total', '10.00')
            ->assertJsonPath('data.sales.payments.0.code', '01')
            ->assertJsonPath('data.movements.0.source', 'SALE');
    }

    public function test_card_payments_are_reported_but_never_counted_as_cash(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $session = CashSession::query()->firstOrFail();
        $sale = $this->makeSale($user, $company, withPayment: false);
        $card = FiscalPaymentMethod::query()->where('code', '19')->firstOrFail();
        $this->actingAs($user)->patchJson("/api/v1/sales/{$sale->public_id}", $this->body($sale, [['fiscalPaymentMethodPublicId' => $card->public_id, 'amount' => '10.00']]))->assertOk();

        $this->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertCreated();

        $this->assertDatabaseCount('cash_movements', 0);
        $this->getJson("/api/v1/cash-sessions/{$session->public_id}")->assertOk()
            ->assertJsonPath('data.balance.expected', '0.00')
            ->assertJsonPath('data.sales.payments.0.code', '19')
            ->assertJsonPath('data.sales.payments.0.amount', '10.00');
    }

    public function test_a_draft_keeps_its_cash_register_and_a_closed_one_is_refused(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $session = CashSession::query()->firstOrFail();
        $sale = $this->makeSale($user, $company);
        $this->assertSame($session->id, $sale->cash_session_id);

        $this->actingAs($user)->patchJson("/api/v1/sales/{$sale->public_id}", [...$this->body($sale), 'notes' => 'Sin cambio de caja'])->assertOk()
            ->assertJsonPath('data.cashSession.publicId', $session->public_id);

        $session->forceFill(['status' => CashSession::CLOSED])->save();
        $this->patchJson("/api/v1/sales/{$sale->public_id}", [...$this->body($sale), 'cashSessionPublicId' => $session->public_id])
            ->assertConflict()->assertJsonPath('error.code', 'CASH_SESSION_NOT_OPEN');
    }

    /** The whole draft again (an update replaces its content), optionally with other payments. */
    private function body(Sale $sale, ?array $payments = null): array
    {
        $sale->load(['customer', 'details.item', 'paymentMethods.fiscalPaymentMethod']);

        return [
            'customerPublicId' => $sale->customer->public_id,
            'lines' => $sale->details->map(fn ($line): array => ['itemPublicId' => $line->item->public_id, 'quantity' => (string) $line->quantity])->all(),
            'paymentMethods' => $payments ?? $sale->paymentMethods->map(fn ($payment): array => ['fiscalPaymentMethodPublicId' => $payment->fiscalPaymentMethod->public_id, 'amount' => (string) $payment->amount])->all(),
        ];
    }
}
