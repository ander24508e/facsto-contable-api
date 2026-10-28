<?php

namespace Tests\Feature\Sri;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Jobs\SubmitDocumentToSriJob;
use App\Domains\Sri\Soap\SriTransportException;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Sales\InvoiceFeatureTestCase;
use Tests\Support\FakesSriWebServices;

/** A document that could not reach the SRI is retried until 23:59:59 (Ecuador) of its issue day. */
final class SriRetryPolicyTest extends InvoiceFeatureTestCase
{
    use FakesSriWebServices;

    public function test_reception_retries_last_until_the_end_of_the_issue_day_in_ecuador(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 10:00:00', 'America/Guayaquil'));
        $invoice = $this->signedInvoice();

        $deadline = (new SubmitDocumentToSriJob($invoice->id))->retryUntil();
        $this->assertSame('2026-10-26T23:59:59-05:00', $deadline?->format(DATE_ATOM));
        $this->assertSame([30, 60, 120, 300, 600], (new SubmitDocumentToSriJob($invoice->id))->backoff());
    }

    public function test_a_failure_after_the_deadline_is_recorded_as_such(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 23:50:00', 'America/Guayaquil'));
        $invoice = $this->signedInvoice();
        $invoice->forceFill(['status' => DocumentStatus::QUEUED])->save();

        $this->travelTo(Carbon::parse('2026-10-27 00:05:00', 'America/Guayaquil'));
        (new SubmitDocumentToSriJob($invoice->id))->failed(new SriTransportException('SRI_CONNECTION_FAILED'));

        $this->assertSame(DocumentStatus::FAILED, $invoice->refresh()->status);
        $this->assertSame('SRI_RETRY_DEADLINE_EXCEEDED', $invoice->last_error_code);
    }

    public function test_the_scheduler_requeues_todays_failures_but_not_older_ones(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 09:00:00', 'America/Guayaquil'));
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $invoice = $this->signedInvoice($user, $company);
        $this->enableSriTransmission($company);
        $invoice->forceFill(['status' => DocumentStatus::FAILED, 'last_error_code' => 'SRI_HTTP_503', 'last_sri_status_at' => now()->subHour()])->save();

        Queue::fake();
        $this->artisan('sri:dispatch-pending')->assertSuccessful();
        Queue::assertPushedOn('sri-submit', SubmitDocumentToSriJob::class, fn (SubmitDocumentToSriJob $job): bool => $job->documentId === $invoice->id);
        $this->assertSame(DocumentStatus::QUEUED, $invoice->refresh()->status);

        // Next day: the automatic window is over; only a manual retry-sri sends it.
        $invoice->forceFill(['status' => DocumentStatus::FAILED, 'last_sri_status_at' => now()->subHour()])->save();
        $this->travelTo(Carbon::parse('2026-10-27 08:00:00', 'America/Guayaquil'));
        Queue::fake();
        $this->artisan('sri:dispatch-pending')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame(DocumentStatus::FAILED, $invoice->refresh()->status);
    }

    public function test_the_test_environment_transmits_while_the_provider_registration_is_pending(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        config(['sri.software_provider.registered' => false]);
        $sale = $this->makeSale($user, $company);
        Http::fake(fn (Request $request) => Http::response($this->receptionResponse('DEVUELTA', '', [['identifier' => '35', 'message' => 'ESTRUCTURA']])));

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.environment', 'TEST')
            ->assertJsonPath('data.status', 'RETURNED');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::RECEPTION_TEST_URL);
    }

    public function test_production_stays_closed_until_the_provider_is_registered(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $company->fiscalSettings()->update(['sri_environment' => 'PRODUCTION']);
        $this->enableSriTransmission($company);
        config(['sri.software_provider.registered' => false]);
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('data.transmissionBlockedReason', 'PROVIDER_NOT_REGISTERED');
        Http::assertNothingSent();
    }

    private function signedInvoice(?User $user = null, $company = null): ElectronicDocument
    {
        $user ??= User::factory()->create();
        $company ??= $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'SIGNED')->json('data');

        return ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
    }
}
