<?php

namespace Tests\Feature\Sri;

use App\Domains\Delivery\Enums\DeliveryStatus;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Actions\ProcessSriReceptionAction;
use App\Domains\Sri\Jobs\CheckAuthorizationJob;
use App\Domains\Sri\Jobs\SubmitDocumentToSriJob;
use App\Domains\Sri\Soap\SriTransportException;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;
use Tests\Support\FakesSriWebServices;

/** F06 + F07 against SOAP answers shaped like the SRI offline services (no real network). */
final class SriTransmissionTest extends InvoiceFeatureTestCase
{
    use FakesSriWebServices;

    public function test_a_closed_gate_keeps_the_document_signed_and_nothing_leaves_facsto(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('data.transmissionBlockedReason', 'TRANSPORT_DISABLED');
        Http::assertNothingSent();
        $this->assertDatabaseCount('sri_submissions', 0);
    }

    public function test_issue_reaches_authorized_with_the_official_authorization_xml_and_ride(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($user, $company);
        $this->fakeSri(fn (string $key, string $signed) => [
            $this->receptionResponse('RECIBIDA'),
            $this->authorizationResponse($key, 'AUTORIZADO', $signed),
        ]);

        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();

        $this->assertSame(DocumentStatus::AUTHORIZED, $invoice->status);
        $this->assertSame($invoice->access_key, $invoice->authorization_number);
        // authorized_at is the SRI fechaAutorizacion, not the local clock.
        $this->assertSame('2026-10-26T15:15:30+00:00', $invoice->authorized_at->utc()->toIso8601String());
        $this->assertNotNull($invoice->last_sri_status_at);

        $authorizedXml = Storage::disk('private')->get($invoice->artifacts()->where('type', DocumentArtifactType::AUTHORIZED_XML->value)->firstOrFail()->path);
        $this->assertStringContainsString('<estado>AUTORIZADO</estado>', $authorizedXml);
        $ride = $invoice->artifacts()->where('type', DocumentArtifactType::RIDE->value)->firstOrFail();
        $this->assertStringStartsWith('%PDF', Storage::disk('private')->get($ride->path));
        $this->assertSame('application/pdf', $ride->mime_type);
        // The customer of makeSale has no email: the delivery says so explicitly.
        $this->assertSame(DeliveryStatus::SKIPPED_NO_RECIPIENT, $invoice->deliveries()->firstOrFail()->status);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::RECEPTION_TEST_URL
            && str_contains($request->body(), '<xml>'.base64_encode(Storage::disk('private')->get($invoice->artifacts()->where('type', 'SIGNED_XML')->firstOrFail()->path)).'</xml>'));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::AUTHORIZATION_TEST_URL
            && str_contains($request->body(), '<claveAccesoComprobante>'.$invoice->access_key.'</claveAccesoComprobante>'));

        $this->actingAs($user)->getJson('/api/v1/invoices/'.$invoice->public_id.'/sri-status')->assertOk()
            ->assertJsonPath('data.status', 'AUTHORIZED')
            ->assertJsonPath('data.rideAvailable', true)
            ->assertJsonPath('data.delivery.status', 'SKIPPED_NO_RECIPIENT')
            ->assertJsonCount(2, 'data.submissions');
        $this->assertSame(0, $invoice->submissions()->whereNull('request_sha256')->count());
        $this->assertDatabaseHas('sri_submissions', ['electronic_document_id' => $invoice->id, 'operation' => 'RECEPTION', 'status' => 'RECEIVED', 'attempt' => 1, 'http_status' => 200]);
        $this->assertDatabaseHas('sri_submissions', ['electronic_document_id' => $invoice->id, 'operation' => 'AUTHORIZATION', 'status' => 'AUTHORIZED']);

        $download = $this->actingAs($user)->get('/api/v1/invoices/'.$invoice->public_id.'/artifacts/ride')->assertOk();
        $this->assertStringStartsWith('%PDF', $download->getContent());
        $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice->public_id.'/retry-sri')->assertConflict()
            ->assertJsonPath('error.code', 'INVOICE_RETRY_NOT_ALLOWED');
        $this->actingAs($user)->getJson('/api/v1/sales?stage=authorized')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document.authorizationNumber', $invoice->access_key);
    }

    public function test_a_returned_document_keeps_the_official_messages(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($user, $company);
        $this->fakeSri(fn (string $key) => [
            $this->receptionResponse('DEVUELTA', $key, [['identifier' => '35', 'message' => 'ARCHIVO NO CUMPLE ESTRUCTURA XML', 'info' => 'Detalle oficial']]),
        ]);

        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');

        $this->actingAs($user)->getJson('/api/v1/invoices/'.$data['publicId'].'/sri-status')->assertOk()
            ->assertJsonPath('data.status', 'RETURNED')
            ->assertJsonPath('data.lastErrorCode', 'SRI_RETURNED')
            ->assertJsonPath('data.submissions.0.status', 'RETURNED')
            ->assertJsonPath('data.submissions.0.messages.0.identifier', '35')
            ->assertJsonPath('data.submissions.0.messages.0.additionalInformation', 'Detalle oficial');
        $this->actingAs($user)->getJson('/api/v1/sales?stage=problems')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_error_43_continues_to_authorization_with_the_same_access_key(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($user, $company);
        $this->fakeSri(fn (string $key, string $signed) => [
            $this->receptionResponse('DEVUELTA', $key, [['identifier' => '43', 'message' => 'CLAVE ACCESO REGISTRADA']]),
            $this->authorizationResponse($key, 'AUTORIZADO', $signed),
        ]);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'AUTHORIZED');
        $this->assertDatabaseHas('sri_submissions', ['operation' => 'RECEPTION', 'status' => 'RECEIVED']);
        $this->assertDatabaseHas('sri_messages', ['identifier' => '43']);
    }

    public function test_pending_authorization_is_asked_again_until_the_sri_decides(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($user, $company);
        $this->fakeSri(fn (string $key, string $signed) => [
            $this->receptionResponse('RECIBIDA'),
            $this->authorizationResponse($key, null),
            $this->authorizationResponse($key, 'EN PROCESO'),
            $this->authorizationResponse($key, 'NO AUTORIZADO', $signed, [['identifier' => '56', 'message' => 'ERROR ESTABLECIMIENTO CERRADO']]),
        ]);

        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();

        $this->assertSame(DocumentStatus::NOT_AUTHORIZED, $invoice->status);
        $this->assertSame('SRI_NOT_AUTHORIZED', $invoice->last_error_code);
        $this->assertNull($invoice->authorized_at);
        $this->assertSame(3, $invoice->submissions()->where('operation', 'AUTHORIZATION')->count());
        $this->assertDatabaseHas('sri_messages', ['identifier' => '56', 'message' => 'ERROR ESTABLECIMIENTO CERRADO']);
        $this->assertSame(0, $invoice->artifacts()->where('type', DocumentArtifactType::AUTHORIZED_XML->value)->count());
    }

    public function test_technical_failures_end_in_failed_and_retry_resends_the_same_signed_xml(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
        $this->enableSriTransmission($company);
        $invoice->forceFill(['status' => DocumentStatus::QUEUED])->save();

        $sriDown = true;
        $this->fakeSri(fn (string $key, string $signed) => [
            $this->receptionResponse('RECIBIDA'),
            $this->authorizationResponse($key, 'AUTORIZADO', $signed),
        ], $sriDown);
        try {
            app(ProcessSriReceptionAction::class)->execute($invoice->id);
            $this->fail('A 503 must be a technical failure.');
        } catch (SriTransportException $exception) {
            (new SubmitDocumentToSriJob($invoice->id))->failed($exception);
        }
        $this->assertSame(DocumentStatus::FAILED, $invoice->refresh()->status);
        $this->assertSame('SRI_HTTP_503', $invoice->last_error_code);
        $this->assertDatabaseHas('sri_submissions', ['operation' => 'RECEPTION', 'status' => 'TRANSPORT_ERROR', 'http_status' => 503, 'error_code' => 'SRI_HTTP_503']);

        $sequential = $invoice->sequential;
        $signedVersions = $invoice->artifacts()->where('type', 'SIGNED_XML')->count();
        $sriDown = false;
        $this->actingAs($user)->postJson('/api/v1/invoices/'.$invoice->public_id.'/retry-sri')->assertOk();

        $invoice->refresh();
        $this->assertSame(DocumentStatus::AUTHORIZED, $invoice->status);
        $this->assertSame($sequential, $invoice->sequential);
        $this->assertSame($signedVersions, $invoice->artifacts()->where('type', 'SIGNED_XML')->count());
        $this->assertSame(2, $invoice->submissions()->where('operation', 'RECEPTION')->max('attempt'));
    }

    public function test_the_environment_of_the_document_snapshot_chooses_the_sri_server(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $company->fiscalSettings()->update(['sri_environment' => 'PRODUCTION']);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($user, $company);
        Http::fake([
            'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline' => Http::response($this->receptionResponse('DEVUELTA', '', [['identifier' => '35', 'message' => 'ESTRUCTURA']])),
        ]);

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'RETURNED')->assertJsonPath('data.environment', 'PRODUCTION');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://cel.sri.gob.ec/'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'celcer'));
    }

    public function test_the_scheduler_resumes_documents_waiting_too_long_for_the_sri(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
        $invoice->forceFill(['status' => DocumentStatus::PENDING_AUTHORIZATION, 'last_sri_status_at' => now()->subHour()])->save();

        Queue::fake();
        $this->artisan('sri:dispatch-pending')->assertSuccessful();
        Queue::assertPushedOn('sri-authorization', CheckAuthorizationJob::class, fn (CheckAuthorizationJob $job): bool => $job->documentId === $invoice->id);

        Queue::fake();
        $invoice->forceFill(['last_sri_status_at' => now()])->save();
        $this->artisan('sri:dispatch-pending')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    /**
     * @param  \Closure(string, string): list<string>  $responses  access key and signed XML → SOAP bodies in call order
     * @param  bool  $unavailable  while true the SRI answers HTTP 503
     */
    private function fakeSri(\Closure $responses, bool &$unavailable = false): void
    {
        $queue = null;
        Http::fake(function () use ($responses, &$queue, &$unavailable) {
            if ($unavailable) {
                return Http::response('Service Unavailable', 503);
            }
            if ($queue === null) {
                $document = ElectronicDocument::query()->latest('id')->firstOrFail();
                $signed = Storage::disk('private')->get($document->artifacts()->where('type', 'SIGNED_XML')->latest('id')->firstOrFail()->path);
                $queue = $responses($document->access_key, $signed);
            }

            return Http::response(array_shift($queue) ?? '', 200, ['Content-Type' => 'text/xml']);
        });
    }
}
