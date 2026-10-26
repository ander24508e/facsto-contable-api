<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceSignedValuesSnapshotTest extends InvoiceFeatureTestCase
{
    public function test_invoice_freezes_total_without_taxes_and_line_price_total_without_tax(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $tax = $this->companyIva($company, '4');
        $sale = $this->makeSale($user, $company, false);
        $item = $sale->details()->firstOrFail()->item;
        $item->taxes()->sync([$tax->id => ['company_id' => $company->id]]);
        $this->actingAs($user)->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id,
            'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '2', 'discountAmount' => '1.00']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => FiscalPaymentMethod::query()->create(['code' => '01', 'name' => 'Sin utilización del sistema financiero', 'valid_from' => today()->subDay(), 'is_active' => true])->public_id, 'amount' => '21.85']],
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated();

        // <totalSinImpuestos> covers every line before taxes, including taxed ones.
        $document = ElectronicDocument::query()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('19.00', $document->total_without_taxes);
        $this->assertSame('0.00', $document->subtotal_without_tax);
        $this->assertSame('1.1.0', $document->xml_version);
        $this->assertSame('19.00', $document->lines()->firstOrFail()->price_total_without_tax);
        $this->assertSame('21.85', $document->total);
    }

    public function test_a_company_never_has_sri_submission_enabled_by_default(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);

        $this->assertFalse($company->fiscalSettings->sri_submission_enabled);
        $this->assertFalse((bool) config('sri.transport_enabled'));
    }
}
