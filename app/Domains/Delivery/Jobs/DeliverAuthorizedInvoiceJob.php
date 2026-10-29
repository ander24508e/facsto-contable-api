<?php

namespace App\Domains\Delivery\Jobs;

use App\Domains\Customers\Models\Customer;
use App\Domains\Delivery\Enums\DeliveryStatus;
use App\Domains\Delivery\Mail\AuthorizedInvoiceMail;
use App\Domains\Delivery\Models\DocumentDelivery;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Ride\Actions\GenerateRideAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F07.3: AUTHORIZED → RIDE → email delivery to the buyer's email and its additional emails. Without SMTP
 * the delivery is recorded as SKIPPED_SMTP_NOT_CONFIGURED; a send is never simulated. With explicit
 * recipients it is a resend requested from Ventas, sent even if the first delivery already went out.
 */
final class DeliverAuthorizedInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param list<string>|null $recipients */
    public function __construct(public readonly int $documentId, public readonly ?array $recipients = null)
    {
        $this->onQueue((string) config('sri.queues.delivery', 'delivery'));
    }

    public function uniqueId(): string
    {
        return $this->documentId.'|DELIVERY|'.($this->recipients === null ? 'FIRST' : md5(implode(',', $this->recipients)));
    }

    public function handle(GenerateRideAction $ride, DocumentArtifactStorage $artifacts): void
    {
        $document = ElectronicDocument::query()->find($this->documentId);
        if ($document?->status !== DocumentStatus::AUTHORIZED) {
            return;
        }
        $rideArtifact = $ride->execute($document);
        $recipients = $this->recipients ?? $this->defaultRecipients($document);

        $delivery = DocumentDelivery::query()->firstOrCreate(
            ['electronic_document_id' => $document->id, 'channel' => DocumentDelivery::CHANNEL_EMAIL],
            ['company_id' => $document->company_id, 'recipient' => $this->recipientList($recipients), 'status' => DeliveryStatus::PENDING],
        );
        if ($this->recipients === null && $delivery->status === DeliveryStatus::SENT) {
            return;
        }

        $skip = match (true) {
            $recipients === [] => DeliveryStatus::SKIPPED_NO_RECIPIENT,
            ! config('sri.delivery.email_enabled') => DeliveryStatus::SKIPPED_SMTP_NOT_CONFIGURED,
            default => null,
        };
        if ($skip !== null) {
            $delivery->forceFill(['status' => $skip, 'recipient' => $this->recipientList($recipients), 'last_error_code' => null])->save();

            return;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1, 'recipient' => $this->recipientList($recipients)])->save();
        try {
            Mail::to($recipients)->send(new AuthorizedInvoiceMail(
                (string) ($document->issuer_commercial_name ?: $document->issuer_legal_name),
                $document->full_number,
                $document->access_key,
                $artifacts->read($artifacts->latest($document, DocumentArtifactType::AUTHORIZED_XML)),
                $artifacts->read($rideArtifact),
                $document->document_type === DocumentType::CREDIT_NOTE ? 'Nota de crédito electrónica' : 'Factura electrónica',
            ));
        } catch (Throwable $exception) {
            $delivery->forceFill(['status' => DeliveryStatus::FAILED, 'last_error_code' => 'MAIL_TRANSPORT_FAILED'])->save();
            throw $exception;
        }
        $delivery->forceFill(['status' => DeliveryStatus::SENT, 'sent_at' => now(), 'last_error_code' => null])->save();
    }

    /** @return list<string> the buyer email frozen in the document plus the customer's current additional emails */
    private function defaultRecipients(ElectronicDocument $document): array
    {
        $additional = Customer::query()->where('company_id', $document->company_id)->whereKey($document->customer_id)->value('additional_emails');
        $additional = is_string($additional) ? (json_decode($additional, true) ?: []) : ($additional ?? []);

        return array_values(array_unique(array_filter([$document->customer_email, ...$additional])));
    }

    /** @param list<string> $recipients */
    private function recipientList(array $recipients): ?string
    {
        return $recipients === [] ? null : mb_substr(implode(', ', $recipients), 0, 1000);
    }
}
