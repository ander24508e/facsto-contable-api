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
        // A returned document is corrected, rebuilt and signed again before resubmission.
        'RETURNED' => [DocumentStatus::XML_BUILT, DocumentStatus::FAILED],
        'PENDING_AUTHORIZATION' => [DocumentStatus::AUTHORIZED, DocumentStatus::NOT_AUTHORIZED, DocumentStatus::FAILED],
        'AUTHORIZED' => [],
        'NOT_AUTHORIZED' => [],
        'FAILED' => [],
    ];

    public function canTransition(DocumentStatus $from, DocumentStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Only a signed XML may reach SRI reception. Draft, numbered and built
     * documents are internal artifacts without electronic validity.
     */
    public function canSubmitToSri(DocumentStatus $status): bool
    {
        return $status === DocumentStatus::SIGNED;
    }

    public function assertCanSubmitToSri(ElectronicDocument $document): void
    {
        if (! $this->canSubmitToSri($document->status)) {
            throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'Sólo un comprobante firmado electrónicamente puede enviarse al SRI.', 409, [
                'from' => $document->status->value,
                'to' => DocumentStatus::QUEUED->value,
            ]);
        }
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
