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

    public function test_the_trend_gives_daily_totals_of_the_month_and_the_last_seven_days(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $invoice = $this->issue($user, $company);
        $invoice->forceFill(['status' => DocumentStatus::AUTHORIZED])->save();
        $this->issue($user, $company)->forceFill(['status' => DocumentStatus::RETURNED])->save();
        $today = now((string) config('sri.timezone'))->toDateString();

        $data = $this->getJson('/api/v1/dashboard/trend?period='.substr($today, 0, 7))->assertOk()->json('data');

        $this->assertCount((int) now((string) config('sri.timezone'))->daysInMonth, $data['days']);
        $this->assertSame(['date' => $today, 'invoiced' => '10.00', 'credited' => '0.00'], collect($data['days'])->firstWhere('date', $today));
        $this->assertCount(7, $data['lastSevenDays']);
        $this->assertSame(['date' => $today, 'authorized' => 1, 'inProcess' => 0, 'retrying' => 0, 'problems' => 1], end($data['lastSevenDays']));
        $this->getJson('/api/v1/dashboard/trend?period=2026-13')->assertUnprocessable();
    }

    private function issue(User $user, $company): ElectronicDocument
    {
        $sale = $this->makeSale($user, $company);
        $id = $this->actingAs($user)->postJson("/api/v1/sales/{$sale->public_id}/issue")->assertCreated()->json('data.publicId');

        return ElectronicDocument::query()->where('public_id', $id)->firstOrFail();
    }
}
