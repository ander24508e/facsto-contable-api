<?php

namespace App\Domains\Sri\Actions;

use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\Contracts\SriProvider;
use App\Domains\Sri\Enums\SriOperation;
use App\Domains\Sri\Enums\SriReceptionStatus;
use App\Domains\Sri\Enums\SriSubmissionStatus;
use App\Domains\Sri\Jobs\CheckAuthorizationJob;
use App\Domains\Sri\Models\SriSubmission;
use App\Domains\Sri\Services\SriSubmissionRecorder;
use App\Domains\Sri\Services\SriTransmissionGate;
use App\Domains\Sri\Soap\SriResponseException;
use App\Domains\Sri\Soap\SriTransportException;
use Illuminate\Support\Facades\DB;

/**
 * QUEUED → RECIBIDA (RECEIVED) / DEVUELTA (RETURNED). Errors 43 and 70 mean the SRI already holds
 * this access key, so the document continues to authorization with the same key.
 */
final class ProcessSriReceptionAction
{
    public function __construct(
        private readonly SriProvider $provider,
        private readonly SriTransmissionGate $gate,
        private readonly SriSubmissionRecorder $recorder,
        private readonly DocumentStateMachine $states,
    ) {}

    public function execute(int $documentId): ElectronicDocument
    {
        /** @var array{ElectronicDocument, SriSubmission|null} $claim */
        $claim = DB::transaction(function () use ($documentId): array {
            $document = ElectronicDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
            if ($document->status !== DocumentStatus::QUEUED) {
                return [$document, null];
            }
            // The gate is checked again right before the XML leaves FACSTO.
            $settings = CompanyFiscalSettings::query()->where('company_id', $document->company_id)->first();
            if (($reason = $this->gate->blockingReason($settings, $document->sri_environment)) !== null) {
                $this->recorder->start($document, SriOperation::RECEPTION, SriSubmissionStatus::BLOCKED, $reason);

                return [$this->states->transition($document, DocumentStatus::SIGNED), null];
            }

            return [$document, $this->recorder->start($document, SriOperation::RECEPTION)];
        });
        [$document, $submission] = $claim;
        if ($submission === null) {
            return $document;
        }

        try {
            $result = $this->provider->submit($document);
        } catch (SriTransportException|SriResponseException $exception) {
            $this->recordFailure($document, $submission, $exception);
            throw $exception;
        }

        return DB::transaction(function () use ($document, $submission, $result): ElectronicDocument {
            $locked = ElectronicDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $received = $result->status === SriReceptionStatus::RECEIVED || $result->alreadyReceived();
            $this->recorder->finish($submission, $received ? SriSubmissionStatus::RECEIVED : SriSubmissionStatus::RETURNED, $result->requestSha256, $result->httpStatus, $result->messages);
            if ($locked->status !== DocumentStatus::QUEUED) {
                return $locked;
            }

            if (! $received) {
                return $this->states->transition($locked, DocumentStatus::RETURNED, ['last_sri_status_at' => now(), 'last_error_code' => 'SRI_RETURNED']);
            }
            $locked = $this->states->transition($locked, DocumentStatus::RECEIVED, ['last_sri_status_at' => now(), 'last_error_code' => null]);
            CheckAuthorizationJob::dispatch($locked->id)->afterCommit()->delay(now()->addSeconds((int) config('sri.retry.authorization_delay', 30)));

            return $locked;
        });
    }

    /** The job gave up after its technical retries: the signed XML is kept for a controlled retry. */
    public function markFailed(int $documentId, string $errorCode): void
    {
        DB::transaction(function () use ($documentId, $errorCode): void {
            $document = ElectronicDocument::query()->whereKey($documentId)->lockForUpdate()->first();
            if ($document?->status === DocumentStatus::QUEUED) {
                $this->states->transition($document, DocumentStatus::FAILED, ['last_error_code' => $errorCode]);
            }
        });
    }

    private function recordFailure(ElectronicDocument $document, SriSubmission $submission, SriTransportException|SriResponseException $exception): void
    {
        DB::transaction(function () use ($document, $submission, $exception): void {
            $status = $exception instanceof SriTransportException ? SriSubmissionStatus::TRANSPORT_ERROR : SriSubmissionStatus::RESPONSE_INVALID;
            $this->recorder->finish($submission, $status, $exception->requestSha256, $exception->httpStatus, [], $exception->reasonCode);
            ElectronicDocument::query()->whereKey($document->id)->update(['last_error_code' => $exception->reasonCode, 'last_sri_status_at' => now()]);
        });
    }
}
