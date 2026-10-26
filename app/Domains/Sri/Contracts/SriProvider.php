<?php

namespace App\Domains\Sri\Contracts;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\DTOs\SriAuthorizationResult;
use App\Domains\Sri\DTOs\SriReceptionResult;
use App\Domains\Sri\Soap\SriResponseException;
use App\Domains\Sri\Soap\SriTransportException;

interface SriProvider
{
    /** @throws SriTransportException|SriResponseException */
    public function submit(ElectronicDocument $document): SriReceptionResult;

    /** @throws SriTransportException|SriResponseException */
    public function authorize(ElectronicDocument $document): SriAuthorizationResult;

    /** @throws SriTransportException|SriResponseException */
    public function query(string $accessKey, string $environment): SriAuthorizationResult;
}
