<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sri\Actions\BuildInvoiceXmlAction;
use App\Domains\Sri\Actions\QueueDocumentForSriAction;
use App\Domains\Sri\Actions\SignElectronicDocumentAction;
use App\Domains\Sri\Jobs\CheckAuthorizationJob;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Resumes an invoice from whatever state it is in: F05 (XML → signature) and then F06 (queue).
 * It never numbers again: the sequential and the access key were frozen by F04.
 */
final class ContinueInvoiceIssuanceAction
{
    public function __construct(
        private readonly BuildInvoiceXmlAction $build,
        private readonly SignElectronicDocumentAction $sign,
        private readonly QueueDocumentForSriAction $queue,
    ) {}

    public function execute(ElectronicDocument $document, User $actor): ElectronicDocument
    {
        // At most: rebuild → sign → queue. Each step leaves a consistent state if the next one fails.
        for ($step = 0; $step < 3; $step++) {
            $document = $document->refresh();
            // A technical F06 failure keeps its verified signed XML: it is queued again, not rebuilt.
            $queue = $document->status === DocumentStatus::SIGNED
                || ($document->status === DocumentStatus::FAILED && $document->signed_at !== null);
            try {
                if ($queue) {
                    $this->queue->execute($document);
                    break;
                }
                if (in_array($document->status, [DocumentStatus::NUMBERED, DocumentStatus::RETURNED, DocumentStatus::NOT_AUTHORIZED, DocumentStatus::FAILED], true)) {
                    $this->build->execute($document, $actor);
                } elseif ($document->status === DocumentStatus::XML_BUILT) {
                    $this->sign->execute($document, $actor);
                } else {
                    break; // already with the SRI, or authorized
                }
            } catch (ApiException $exception) {
                throw $this->remember($document, $exception);
            }
        }

        return $document->refresh()->load(['sale', 'lines.taxes', 'taxes', 'paymentMethods']);
    }

    /** POST /invoices/{invoice}/retry-sri: a controlled retry that keeps number and access key. */
    public function retry(ElectronicDocument $document, User $actor): ElectronicDocument
    {
        if ($document->sale?->status === SaleStatus::IN_CORRECTION) {
            throw new ApiException(ApiErrorCode::INVOICE_RETRY_NOT_ALLOWED, 'La venta está en corrección: guárdala y emítela de nuevo.', 409);
        }

        return match ($document->status) {
            DocumentStatus::AUTHORIZED => throw new ApiException(ApiErrorCode::INVOICE_RETRY_NOT_ALLOWED, 'El comprobante ya fue autorizado por el SRI.', 409),
            DocumentStatus::RECEIVED, DocumentStatus::PENDING_AUTHORIZATION => $this->askAuthorization($document),
            default => $this->execute($document, $actor),
        };
    }

    private function askAuthorization(ElectronicDocument $document): ElectronicDocument
    {
        CheckAuthorizationJob::dispatch($document->id);

        return $document->refresh()->load(['sale', 'lines.taxes', 'taxes', 'paymentMethods']);
    }

    /** The failure is kept on the document so Ventas can explain it and offer the retry. */
    private function remember(ElectronicDocument $document, ApiException $exception): ApiException
    {
        $reason = $exception->details['reason_code'] ?? ($exception->details['pending'][0] ?? $exception->errorCode->value);
        $document->forceFill(['last_error_code' => mb_substr((string) $reason, 0, 64)])->save();

        return new ApiException($exception->errorCode, $exception->getMessage(), $exception->status, [
            ...$exception->details,
            'invoicePublicId' => $document->public_id,
            'invoiceStatus' => $document->status->value,
        ]);
    }
}
