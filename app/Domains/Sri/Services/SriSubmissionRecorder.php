<?php

namespace App\Domains\Sri\Services;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\DTOs\SriMessageData;
use App\Domains\Sri\Enums\SriOperation;
use App\Domains\Sri\Enums\SriSubmissionStatus;
use App\Domains\Sri\Models\SriSubmission;

/** Audit trail of every SRI interaction (attempt, request hash, HTTP status, official messages). */
final class SriSubmissionRecorder
{
    /** Must run inside the transaction that holds the document lock. */
    public function start(ElectronicDocument $document, SriOperation $operation, SriSubmissionStatus $status = SriSubmissionStatus::IN_PROGRESS, ?string $errorCode = null): SriSubmission
    {
        $attempt = (int) SriSubmission::query()
            ->where('electronic_document_id', $document->id)
            ->where('operation', $operation->value)
            ->max('attempt') + 1;

        return SriSubmission::query()->create([
            'company_id' => $document->company_id,
            'electronic_document_id' => $document->id,
            'operation' => $operation,
            'attempt' => $attempt,
            'endpoint_environment' => (string) $document->sri_environment,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => $status === SriSubmissionStatus::IN_PROGRESS ? null : now(),
            'error_code' => $errorCode,
        ]);
    }

    /** @param list<SriMessageData> $messages */
    public function finish(SriSubmission $submission, SriSubmissionStatus $status, ?string $requestSha256, ?int $httpStatus, array $messages = [], ?string $errorCode = null): SriSubmission
    {
        $submission->forceFill([
            'status' => $status,
            'request_sha256' => $requestSha256,
            'http_status' => $httpStatus,
            'finished_at' => now(),
            'error_code' => $errorCode,
        ])->save();
        foreach ($messages as $message) {
            $submission->messages()->create([
                'company_id' => $submission->company_id,
                'identifier' => $message->identifier,
                'type' => $message->type,
                'message' => $message->message,
                'additional_information' => $message->additionalInformation,
            ]);
        }

        return $submission;
    }
}
