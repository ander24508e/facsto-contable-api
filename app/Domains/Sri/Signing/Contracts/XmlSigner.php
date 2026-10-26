<?php

namespace App\Domains\Sri\Signing\Contracts;

use App\Domains\Certificates\DTOs\CertificateMaterial;
use App\Domains\Sri\Signing\XmlSigningException;

interface XmlSigner
{
    /**
     * Returns the comprobante with its enveloped XAdES-BES signature (ficha técnica, anexo 14).
     *
     * @throws XmlSigningException
     */
    public function sign(string $unsignedXml, CertificateMaterial $material): string;
}
