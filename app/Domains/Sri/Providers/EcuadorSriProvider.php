<?php

namespace App\Domains\Sri\Providers;

use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Sri\Contracts\SriProvider;
use App\Domains\Sri\DTOs\SriAuthorizationResult;
use App\Domains\Sri\DTOs\SriReceptionResult;
use App\Domains\Sri\Soap\SriAuthorizationClient;
use App\Domains\Sri\Soap\SriReceptionClient;
use App\Domains\Sri\Soap\SriTransportException;

/** SRI Ecuador, offline scheme: the latest SIGNED_XML goes to reception; authorization uses the access key. */
final class EcuadorSriProvider implements SriProvider
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts,
        private readonly SriReceptionClient $reception,
        private readonly SriAuthorizationClient $authorization,
    ) {}

    public function submit(ElectronicDocument $document): SriReceptionResult
    {
        $artifact = $this->artifacts->latest($document, DocumentArtifactType::SIGNED_XML);
        $signedXml = $this->artifacts->read($artifact);
        // What leaves FACSTO is exactly the verified signed XML that was stored.
        if (! hash_equals($artifact->sha256, hash('sha256', $signedXml))) {
            throw new SriTransportException('SIGNED_XML_INTEGRITY_FAILED');
        }

        return $this->reception->validate($signedXml, (string) $document->sri_environment);
    }

    public function authorize(ElectronicDocument $document): SriAuthorizationResult
    {
        return $this->query($document->access_key, (string) $document->sri_environment);
    }

    public function query(string $accessKey, string $environment): SriAuthorizationResult
    {
        return $this->authorization->authorize($accessKey, $environment);
    }
}
