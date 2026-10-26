<?php

namespace App\Domains\Sri\Actions;

use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\DTOs\SriAuthorizationResult;
use App\Domains\Sri\Enums\SriAuthorizationStatus;
use DOMDocument;

/**
 * F07.1: keeps the official <autorizacion> answer as AUTHORIZED_XML and freezes
 * authorization_number and authorized_at exactly as the SRI reported them.
 */
final class StoreAuthorizedXmlAction
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts,
        private readonly DocumentStateMachine $states,
    ) {}

    /** The authorized comprobante must be this document: same access key inside the embedded XML. */
    public function belongsTo(ElectronicDocument $document, SriAuthorizationResult $result): bool
    {
        if ($result->status !== SriAuthorizationStatus::AUTHORIZED || $result->authorizationXml === null || $result->comprobante === null) {
            return false;
        }
        $comprobante = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $comprobante->loadXML($result->comprobante, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $comprobante->doctype !== null) {
            return false;
        }
        $key = $comprobante->getElementsByTagName('claveAcceso')->item(0)?->textContent;

        return $key !== null && hash_equals($document->access_key, trim($key));
    }

    /** Runs inside the transaction that holds the document lock. */
    public function execute(ElectronicDocument $locked, SriAuthorizationResult $result): ElectronicDocument
    {
        $artifact = $this->artifacts->store($locked, DocumentArtifactType::AUTHORIZED_XML, (string) $result->authorizationXml);
        try {
            return $this->states->transition($locked, DocumentStatus::AUTHORIZED, [
                'authorization_number' => $result->authorizationNumber,
                'authorized_at' => $result->authorizedAt,
                'last_sri_status_at' => now(),
                'last_error_code' => null,
            ]);
        } catch (\Throwable $exception) {
            $this->artifacts->discardFile($artifact);
            throw $exception;
        }
    }
}
