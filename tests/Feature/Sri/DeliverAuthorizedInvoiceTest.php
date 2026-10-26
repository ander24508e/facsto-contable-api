<?php

namespace Tests\Feature\Sri;

use App\Domains\Delivery\Enums\DeliveryStatus;
use App\Domains\Delivery\Jobs\DeliverAuthorizedInvoiceJob;
use App\Domains\Delivery\Mail\AuthorizedInvoiceMail;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;
use Tests\Support\FakesSriWebServices;

/** F07.3: delivery never pretends a send; with SMTP enabled it attaches the authorized XML and the RIDE. */
final class DeliverAuthorizedInvoiceTest extends InvoiceFeatureTestCase
{
    use FakesSriWebServices;

    public function test_without_smtp_the_delivery_is_recorded_as_skipped(): void
    {
        Mail::fake();
        $invoice = $this->authorizedInvoice();

        $this->assertSame(DeliveryStatus::SKIPPED_SMTP_NOT_CONFIGURED, $invoice->deliveries()->firstOrFail()->status);
        $this->assertSame('cliente@example.com', $invoice->deliveries()->firstOrFail()->recipient);
        Mail::assertNothingSent();
    }

    public function test_with_smtp_enabled_the_customer_receives_the_xml_and_the_ride(): void
    {
        Mail::fake();
        $invoice = $this->authorizedInvoice();
        config(['sri.delivery.email_enabled' => true]);

        app()->call([new DeliverAuthorizedInvoiceJob($invoice->id), 'handle']);

        Mail::assertSent(AuthorizedInvoiceMail::class, fn (AuthorizedInvoiceMail $mail): bool => $mail->hasTo('cliente@example.com')
            && $mail->accessKey === $invoice->access_key
            && count($mail->attachments()) === 2);
        $delivery = $invoice->deliveries()->firstOrFail();
        $this->assertSame(DeliveryStatus::SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts);
    }

    private function authorizedInvoice(): ElectronicDocument
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        $sale = $this->makeSale($user, $company);
        $sale->forceFill(['customer_email' => 'cliente@example.com'])->save();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'Recepcion')) {
                return Http::response($this->receptionResponse('RECIBIDA'));
            }
            $document = ElectronicDocument::query()->latest('id')->firstOrFail();
            $signed = Storage::disk('private')->get($document->artifacts()->where('type', 'SIGNED_XML')->firstOrFail()->path);

            return Http::response($this->authorizationResponse($document->access_key, 'AUTORIZADO', $signed));
        });

        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'AUTHORIZED')->json('data');

        return ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
    }
}
