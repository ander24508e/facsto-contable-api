<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class DocumentStateMachine
{
    /** @var array<string, list<DocumentStatus>> */
    private const TRANSITIONS = [
        'DRAFT' => [DocumentStatus::NUMBERED, DocumentStatus::FAILED],
        'NUMBERED' => [DocumentStatus::XML_BUILT, DocumentStatus::FAILED],
        'XML_BUILT' => [DocumentStatus::SIGNED, DocumentStatus::FAILED],
        'SIGNED' => [DocumentStatus::QUEUED, DocumentStatus::FAILED],
        'QUEUED' => [DocumentStatus::RECEIVED, DocumentStatus::RETURNED, DocumentStatus::FAILED],
        'RECEIVED' => [DocumentStatus::PENDING_AUTHORIZATION, DocumentStatus::RETURNED, DocumentStatus::FAILED],
        'RETURNED' => [DocumentStatus::QUEUED, DocumentStatus::FAILED],
        'PENDING_AUTHORIZATION' => [DocumentStatus::AUTHORIZED, DocumentStatus::NOT_AUTHORIZED, DocumentStatus::FAILED],
        'AUTHORIZED' => [],
        'NOT_AUTHORIZED' => [],
        'FAILED' => [],
    ];

    public function canTransition(DocumentStatus $from, DocumentStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    public function transition(ElectronicDocument $document, DocumentStatus $to): ElectronicDocument
    {
        if (! $this->canTransition($document->status, $to)) {
            throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'La transición solicitada no está permitida.', 409, [
                'from' => $document->status->value,
                'to' => $to->value,
            ]);
        }

        $document->forceFill(['status' => $to])->save();

        return $document->refresh();
    }
}
