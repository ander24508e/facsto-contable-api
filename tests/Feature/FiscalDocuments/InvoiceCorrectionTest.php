<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** "Corregir y reemitir": a returned / not authorized invoice keeps its number and gets a new access key. */
final class InvoiceCorrectionTest extends InvoiceFeatureTestCase
{
    public function test_a_returned_invoice_is_corrected_and_reissued_with_the_same_number(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $issued = $this->actingAs($user)->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertCreated()->json('data');
        $document = ElectronicDocument::query()->where('public_id', $issued['publicId'])->firstOrFail();
        $document->forceFill(['status' => DocumentStatus::RETURNED, 'last_error_code' => '43'])->save();
        $previousKey = $document->access_key;

        $this->postJson("/api/v1/invoices/{$document->public_id}/correct")->assertOk()
            ->assertJsonPath('data.status', 'IN_CORRECTION');
        $this->postJson("/api/v1/invoices/{$document->public_id}/retry-sri")->assertConflict();

        $line = $sale->refresh()->load('details.item')->details->first();
        $customer = $sale->customer;
        $this->patchJson("/api/v1/sales/{$sale->public_id}", [
            'customerPublicId' => $customer->public_id,
            'lines' => [['itemPublicId' => $line->item->public_id, 'quantity' => '2']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '20.00']],
        ])->assertOk()->assertJsonPath('data.status', 'IN_CORRECTION');

        $this->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertOk()
            ->assertJsonPath('data.fullNumber', $issued['fullNumber'])
            ->assertJsonPath('data.status', 'SIGNED');

        $document->refresh();
        $this->assertNotSame($previousKey, $document->access_key);
        $this->assertSame([$previousKey], $document->previous_access_keys);
        $this->assertSame('20.00', (string) $document->total);
        $this->assertSame('DOCUMENT_PREPARED', $sale->refresh()->status->value);
        $this->assertSame(1, ElectronicDocument::query()->count());
    }

    public function test_only_returned_or_not_authorized_invoices_can_be_corrected(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $publicId = $this->actingAs($user)->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertCreated()->json('data.publicId');

        $this->postJson("/api/v1/invoices/{$publicId}/correct")->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_DOCUMENT_TRANSITION');
    }
}
