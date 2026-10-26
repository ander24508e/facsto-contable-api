<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class TipFiscalRuleTest extends InvoiceFeatureTestCase
{
    public function test_tip_above_ten_percent_of_the_subtotal_is_rejected(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);

        $this->saveWithTip($user, $sale, '1.01', '11.01')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TIP_MAX_PERCENTAGE_EXCEEDED')
            ->assertJsonPath('error.details.maximumTip', '1.00');
    }

    public function test_tip_up_to_the_limit_is_added_to_the_total_and_frozen_in_the_document(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);

        $this->saveWithTip($user, $sale, '1.00', '11.00')->assertOk()
            ->assertJsonPath('data.tipAmount', '1.00')->assertJsonPath('data.total', '11.00');
        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')->assertCreated();

        $document = ElectronicDocument::query()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('1.00', $document->tip_amount);
        $this->assertSame('11.00', $document->total);
        $this->assertSame('10.00', $document->total_without_taxes);
        $this->assertSame(today()->toDateString(), $sale->refresh()->issue_date->toDateString());
    }

    private function saveWithTip(User $user, Sale $sale, string $tip, string $paymentAmount)
    {
        return $this->actingAs($user)->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id,
            'lines' => [['itemPublicId' => $sale->details()->firstOrFail()->item->public_id, 'quantity' => '1']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => $paymentAmount]],
            'tipAmount' => $tip,
        ]);
    }
}
