<?php

namespace Tests\Feature\FiscalDocuments;

use App\Domains\Delivery\Mail\AuthorizedInvoiceMail;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;
use Tests\Support\FakesSriWebServices;

/** Ventas after issuing: timeline, resend, share link, lookup by key or number, and bulk actions. */
final class InvoiceOperationsTest extends InvoiceFeatureTestCase
{
    use FakesSriWebServices;

    private User $user;

    public function test_the_timeline_shows_every_stage_and_the_sri_attempts(): void
    {
        $invoice = $this->authorizedInvoice();

        $types = collect($this->getJson("/api/v1/invoices/{$invoice->public_id}/timeline")->assertOk()->json('data'))->pluck('type');
        $this->assertSame(['NUMBERED', 'XML_BUILT', 'SIGNED'], $types->take(3)->all());
        $this->assertContains('SRI_RECEPTION', $types);
        $this->assertContains('AUTHORIZED', $types);
        $this->assertContains('DELIVERY_EMAIL', $types);
    }

    public function test_an_authorized_invoice_is_resent_to_other_emails(): void
    {
        $invoice = $this->authorizedInvoice();
        Mail::fake();
        config(['sri.delivery.email_enabled' => true]);

        $this->postJson("/api/v1/invoices/{$invoice->public_id}/deliveries", ['emails' => ['Contabilidad@Example.com', 'compras@example.com']])
            ->assertStatus(202)->assertJsonPath('data.recipients', ['contabilidad@example.com', 'compras@example.com']);

        Mail::assertSent(AuthorizedInvoiceMail::class, fn (AuthorizedInvoiceMail $mail): bool => $mail->hasTo('contabilidad@example.com') && $mail->hasTo('compras@example.com'));
        $this->assertContains('RESENT', collect($this->getJson("/api/v1/invoices/{$invoice->public_id}/timeline")->json('data'))->pluck('type'));
    }

    public function test_the_share_link_opens_the_ride_without_a_session_until_it_expires(): void
    {
        $invoice = $this->authorizedInvoice();
        $url = $this->postJson("/api/v1/invoices/{$invoice->public_id}/share-link")->assertOk()->json('data.url');

        auth()->guard('web')->logout();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_an_invoice_is_found_by_access_key_number_or_sequential(): void
    {
        $invoice = $this->authorizedInvoice();

        $this->getJson('/api/v1/invoices/lookup?q='.$invoice->access_key)->assertOk()->assertJsonPath('data.publicId', $invoice->public_id);
        $this->getJson('/api/v1/invoices/lookup?q=001-001-1')->assertOk()->assertJsonPath('data.publicId', $invoice->public_id);
        $this->getJson('/api/v1/invoices/lookup?q=1')->assertOk()->assertJsonPath('data.publicId', $invoice->public_id);
        $this->getJson('/api/v1/invoices/lookup?q=001-001-999')->assertNotFound();
        $this->getJson('/api/v1/sales?search=001-001-000000001')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_access_key_barcode_is_served_for_the_ride_preview(): void
    {
        $invoice = $this->authorizedInvoice();

        $svg = $this->getJson("/api/v1/invoices/{$invoice->public_id}/barcode")->assertOk()
            ->assertJsonPath('data.accessKey', $invoice->access_key)
            ->json('data.svg');
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }

    public function test_bulk_export_zips_the_xml_and_ride_of_authorized_invoices(): void
    {
        $invoice = $this->authorizedInvoice();

        $response = $this->post('/api/v1/invoices/bulk/export', ['invoicePublicIds' => [$invoice->public_id]], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('001-001-000000001.pdf'));
    }

    public function test_bulk_retry_reports_each_invoice_on_its_own(): void
    {
        $invoice = $this->authorizedInvoice();
        $sale = $this->makeSale($this->user, $invoice->company);
        $other = ElectronicDocument::query()->where('public_id', $this->postJson("/api/v1/sales/{$sale->public_id}/issue")->json('data.publicId'))->firstOrFail();
        $other->forceFill(['status' => DocumentStatus::RETURNED])->save();
        Http::fake(fn () => Http::response($this->receptionResponse('RECIBIDA')));

        $this->postJson('/api/v1/invoices/bulk/retry-sri', ['invoicePublicIds' => [$invoice->public_id, $other->public_id]])->assertOk()
            ->assertJsonPath('meta.succeeded', 1)
            ->assertJsonPath('meta.failed', 1)
            ->assertJsonPath('data.0.errorCode', 'INVOICE_RETRY_NOT_ALLOWED');
    }

    public function test_a_credit_note_returns_part_of_an_authorized_invoice_through_the_sri(): void
    {
        $invoice = $this->authorizedInvoice();
        $uri = "/api/v1/invoices/{$invoice->public_id}/credit-notes";

        $this->postJson($uri, ['reason' => 'Devolución', 'lines' => [['lineNumber' => 1, 'quantity' => '2']]])
            ->assertUnprocessable();
        $creditNote = $this->postJson($uri, ['reason' => 'Devolución del servicio', 'lines' => [['lineNumber' => 1, 'quantity' => '1']]])
            ->assertCreated()
            ->assertJsonPath('data.documentType', 'CREDIT_NOTE')
            ->assertJsonPath('data.status', 'AUTHORIZED')
            ->assertJsonPath('data.fullNumber', '001-001-000000001')
            ->assertJsonPath('data.modifiedDocument.fullNumber', $invoice->full_number)
            ->assertJsonPath('data.totals.total', '10.00')
            ->json('data');

        $this->assertSame('04', substr($creditNote['accessKey'], 8, 2));
        $signed = Storage::disk('private')->get(ElectronicDocument::query()->where('public_id', $creditNote['publicId'])->firstOrFail()
            ->artifacts()->where('type', 'SIGNED_XML')->firstOrFail()->path);
        $this->assertStringContainsString('<numDocModificado>001-001-000000001</numDocModificado>', $signed);
        $this->assertStringContainsString('<motivo>Devolución del servicio</motivo>', $signed);
        $this->get("/api/v1/invoices/{$creditNote['publicId']}/artifacts/ride")->assertOk();
        $this->postJson($uri, ['reason' => 'Otra', 'lines' => [['lineNumber' => 1, 'quantity' => '0.5']]])
            ->assertUnprocessable();
        $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data');
    }

    private function authorizedInvoice(): ElectronicDocument
    {
        $this->user = User::factory()->create();
        $company = $this->readyCompany($this->user);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($this->user, $company);
        $sale->forceFill(['customer_email' => 'cliente@example.com'])->save();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'Recepcion')) {
                return Http::response($this->receptionResponse('RECIBIDA'));
            }
            $document = ElectronicDocument::query()->latest('id')->firstOrFail();
            $signed = Storage::disk('private')->get($document->artifacts()->where('type', 'SIGNED_XML')->firstOrFail()->path);

            return Http::response($this->authorizationResponse($document->access_key, 'AUTORIZADO', $signed));
        });

        $data = $this->actingAs($this->user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'AUTHORIZED')->json('data');

        return ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
    }
}
