<?php

namespace App\Domains\Delivery\Jobs;

use App\Domains\Delivery\Enums\DeliveryStatus;
use App\Domains\Delivery\Mail\AuthorizedInvoiceMail;
use App\Domains\Delivery\Models\DocumentDelivery;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Ride\Actions\GenerateRideAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F07.3: AUTHORIZED → RIDE → email delivery. Without SMTP the delivery is recorded as
 * SKIPPED_SMTP_NOT_CONFIGURED; a send is never simulated.
 */
final class DeliverAuthorizedInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue((string) config('sri.queues.delivery', 'delivery'));
    }

    public function uniqueId(): string
    {
        return $this->documentId.'|DELIVERY';
    }

    public function handle(GenerateRideAction $ride, DocumentArtifactStorage $artifacts): void
    {
        $document = ElectronicDocument::query()->find($this->documentId);
        if ($document?->status !== DocumentStatus::AUTHORIZED) {
            return;
        }
        $rideArtifact = $ride->execute($document);

        $delivery = DocumentDelivery::query()->firstOrCreate(
            ['electronic_document_id' => $document->id, 'channel' => DocumentDelivery::CHANNEL_EMAIL],
            ['company_id' => $document->company_id, 'recipient' => $document->customer_email, 'status' => DeliveryStatus::PENDING],
        );
        if ($delivery->status === DeliveryStatus::SENT) {
            return;
        }

        $skip = match (true) {
            blank($document->customer_email) => DeliveryStatus::SKIPPED_NO_RECIPIENT,
            ! config('sri.delivery.email_enabled') => DeliveryStatus::SKIPPED_SMTP_NOT_CONFIGURED,
            default => null,
        };
        if ($skip !== null) {
            $delivery->forceFill(['status' => $skip, 'recipient' => $document->customer_email, 'last_error_code' => null])->save();

            return;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();
        try {
            Mail::to((string) $document->customer_email)->send(new AuthorizedInvoiceMail(
                (string) ($document->issuer_commercial_name ?: $document->issuer_legal_name),
                $document->full_number,
                $document->access_key,
                $artifacts->read($artifacts->latest($document, DocumentArtifactType::AUTHORIZED_XML)),
                $artifacts->read($rideArtifact),
            ));
        } catch (Throwable $exception) {
            $delivery->forceFill(['status' => DeliveryStatus::FAILED, 'last_error_code' => 'MAIL_TRANSPORT_FAILED'])->save();
            throw $exception;
        }
        $delivery->forceFill(['status' => DeliveryStatus::SENT, 'sent_at' => now(), 'last_error_code' => null])->save();
    }
}
