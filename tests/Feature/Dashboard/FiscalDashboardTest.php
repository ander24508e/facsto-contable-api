<?php

namespace Tests\Feature\Dashboard;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class FiscalDashboardTest extends InvoiceFeatureTestCase
{
    public function test_the_fiscal_status_counts_todays_documents_and_lists_pending_actions(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $first = $this->issue($user, $company);
        $second = $this->issue($user, $company);
        $first->forceFill(['status' => DocumentStatus::AUTHORIZED])->save();
        $second->forceFill(['status' => DocumentStatus::RETURNED])->save();

        $this->getJson('/api/v1/dashboard/fiscal-status')->assertOk()
            ->assertJsonPath('data.authorized', 1)
            ->assertJsonPath('data.problems', 1)
            ->assertJsonPath('data.usage.documentsThisMonth', 2)
            ->assertJsonPath('data.usage.limit', null);

        $codes = collect($this->getJson('/api/v1/dashboard/actions')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('DOCUMENTS_RETURNED', $codes);
        $this->assertContains('ELECTRONIC_INVOICING_OFF', $codes);
    }

    public function test_the_tax_summary_nets_credit_notes_per_rate(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $invoice = $this->issue($user, $company);
        $invoice->forceFill(['status' => DocumentStatus::AUTHORIZED])->save();

        $this->getJson('/api/v1/reports/tax-summary?period='.now()->format('Y-m'))->assertOk()
            ->assertJsonPath('data.invoiceCount', 1)
            ->assertJsonPath('data.invoicedTotal', '10.00')
            ->assertJsonPath('data.rates.0.percentageCode', '0')
            ->assertJsonPath('data.rates.0.netBase', '10.00');
        $this->getJson('/api/v1/reports/tax-summary?period=2026-13')->assertUnprocessable();
    }

    private function issue(User $user, $company): ElectronicDocument
    {
        $sale = $this->makeSale($user, $company);
        $id = $this->actingAs($user)->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertCreated()->json('data.publicId');

        return ElectronicDocument::query()->where('public_id', $id)->firstOrFail();
    }
}
