<?php

namespace App\Domains\Sri\Actions;

use App\Domains\Delivery\Jobs\DeliverAuthorizedInvoiceJob;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\Contracts\SriProvider;
use App\Domains\Sri\DTOs\SriAuthorizationResult;
use App\Domains\Sri\Enums\SriAuthorizationStatus;
use App\Domains\Sri\Enums\SriOperation;
use App\Domains\Sri\Enums\SriSubmissionStatus;
use App\Domains\Sri\Models\SriSubmission;
use App\Domains\Sri\Services\SriSubmissionRecorder;
use App\Domains\Sri\Soap\SriResponseException;
use App\Domains\Sri\Soap\SriTransportException;
use Illuminate\Support\Facades\DB;

/** RECEIVED / PENDING_AUTHORIZATION → AUTHORIZED, NOT_AUTHORIZED or still PENDING_AUTHORIZATION. */
final class ProcessSriAuthorizationAction
{
    private const AWAITING = [DocumentStatus::RECEIVED, DocumentStatus::PENDING_AUTHORIZATION];

    public function __construct(
        private readonly SriProvider $provider,
        private readonly SriSubmissionRecorder $recorder,
        private readonly DocumentStateMachine $states,
        private readonly StoreAuthorizedXmlAction $store,
    ) {}

    /** @return SriAuthorizationStatus|null null when the document no longer waits for an authorization */
    public function execute(int $documentId): ?SriAuthorizationStatus
    {
        /** @var array{ElectronicDocument, SriSubmission|null} $claim */
        $claim = DB::transaction(function () use ($documentId): array {
            $document = ElectronicDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
            if (! in_array($document->status, self::AWAITING, true)) {
                return [$document, null];
            }

            return [$document, $this->recorder->start($document, SriOperation::AUTHORIZATION)];
        });
        [$document, $submission] = $claim;
        if ($submission === null) {
            return null;
        }

        try {
            $result = $this->provider->authorize($document);
            if ($result->status === SriAuthorizationStatus::AUTHORIZED && ! $this->store->belongsTo($document, $result)) {
                throw new SriResponseException('AUTHORIZED_COMPROBANTE_MISMATCH', $result->httpStatus, $result->requestSha256);
            }
        } catch (SriTransportException|SriResponseException $exception) {
            DB::transaction(function () use ($document, $submission, $exception): void {
                $status = $exception instanceof SriTransportException ? SriSubmissionStatus::TRANSPORT_ERROR : SriSubmissionStatus::RESPONSE_INVALID;
                $this->recorder->finish($submission, $status, $exception->requestSha256, $exception->httpStatus, [], $exception->reasonCode);
                ElectronicDocument::query()->whereKey($document->id)->update(['last_error_code' => $exception->reasonCode, 'last_sri_status_at' => now()]);
            });
            throw $exception;
        }

        return DB::transaction(function () use ($document, $submission, $result): ?SriAuthorizationStatus {
            $locked = ElectronicDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->recorder->finish($submission, match ($result->status) {
                SriAuthorizationStatus::AUTHORIZED => SriSubmissionStatus::AUTHORIZED,
                SriAuthorizationStatus::NOT_AUTHORIZED => SriSubmissionStatus::NOT_AUTHORIZED,
                SriAuthorizationStatus::PENDING => SriSubmissionStatus::PENDING,
            }, $result->requestSha256, $result->httpStatus, $result->messages);
            if (! in_array($locked->status, self::AWAITING, true)) {
                return null;
            }

            match ($result->status) {
                SriAuthorizationStatus::AUTHORIZED => $this->authorized($locked, $result),
                SriAuthorizationStatus::NOT_AUTHORIZED => $this->states->transition($locked, DocumentStatus::NOT_AUTHORIZED, ['last_sri_status_at' => now(), 'last_error_code' => 'SRI_NOT_AUTHORIZED']),
                SriAuthorizationStatus::PENDING => $locked->status === DocumentStatus::RECEIVED
                    ? $this->states->transition($locked, DocumentStatus::PENDING_AUTHORIZATION, ['last_sri_status_at' => now()])
                    : $locked->forceFill(['last_sri_status_at' => now()])->save(),
            };

            return $result->status;
        });
    }

    private function authorized(ElectronicDocument $locked, SriAuthorizationResult $result): ElectronicDocument
    {
        $document = $this->store->execute($locked, $result);
        DeliverAuthorizedInvoiceJob::dispatch($document->id)->afterCommit();

        return $document;
    }
}
