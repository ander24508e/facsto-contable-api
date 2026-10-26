<?php

namespace App\Http\Resources\Api\V1;

use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\Sri\Services\SriTransmissionGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvoiceSriStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $delivery = $this->deliveries->first();
        $artifactTypes = $this->artifacts->map(fn ($artifact): string => $artifact->type->value)->unique()->values();

        return [
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'environment' => $this->sri_environment,
            'accessKey' => $this->access_key,
            'authorizationNumber' => $this->authorization_number,
            'authorizedAt' => $this->authorized_at?->toIso8601String(),
            'lastSriStatusAt' => $this->last_sri_status_at?->toIso8601String(),
            'lastErrorCode' => $this->last_error_code,
            'transmissionBlockedReason' => $this->status === DocumentStatus::SIGNED
                ? app(SriTransmissionGate::class)->blockingReason(CompanyFiscalSettings::query()->where('company_id', $this->company_id)->first())
                : null,
            'canRetry' => ! in_array($this->status, [DocumentStatus::AUTHORIZED, DocumentStatus::QUEUED], true),
            'timeline' => [
                'numberedAt' => $this->numbered_at?->toIso8601String(),
                'xmlBuiltAt' => $this->xml_built_at?->toIso8601String(),
                'signedAt' => $this->signed_at?->toIso8601String(),
                'sentAt' => $this->submissions->where('operation.value', 'RECEPTION')->whereNotIn('status.value', ['BLOCKED'])->min('started_at')?->toIso8601String(),
                'receivedAt' => $this->submissions->where('operation.value', 'RECEPTION')->where('status.value', 'RECEIVED')->min('finished_at')?->toIso8601String(),
                'authorizedAt' => $this->authorized_at?->toIso8601String(),
            ],
            'artifacts' => $artifactTypes,
            'rideAvailable' => $this->status === DocumentStatus::AUTHORIZED,
            'authorizedXmlAvailable' => $artifactTypes->contains(DocumentArtifactType::AUTHORIZED_XML->value),
            'submissions' => $this->submissions->map(fn ($submission): array => [
                'publicId' => $submission->public_id,
                'operation' => $submission->operation->value,
                'attempt' => $submission->attempt,
                'environment' => $submission->endpoint_environment,
                'status' => $submission->status->value,
                'httpStatus' => $submission->http_status,
                'errorCode' => $submission->error_code,
                'startedAt' => $submission->started_at?->toIso8601String(),
                'finishedAt' => $submission->finished_at?->toIso8601String(),
                'messages' => $submission->messages->map(fn ($message): array => [
                    'identifier' => $message->identifier,
                    'type' => $message->type,
                    'message' => $message->message,
                    'additionalInformation' => $message->additional_information,
                ])->values(),
            ])->values(),
            'delivery' => $delivery ? [
                'channel' => $delivery->channel,
                'status' => $delivery->status->value,
                'recipient' => $delivery->recipient,
                'attempts' => $delivery->attempts,
                'sentAt' => $delivery->sent_at?->toIso8601String(),
            ] : null,
        ];
    }
}
