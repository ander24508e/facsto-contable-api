<?php

namespace App\Domains\Sri\Signing;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Independent cryptographic check of a XAdES-BES signature before a document becomes SIGNED.
 * It does not trust the signer: every digest and the SignatureValue are recomputed here.
 */
final class SignatureValidator
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const RSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';

    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const SIGNED_PROPERTIES_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    private const DIGESTS = [
        'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
    ];

    /** @param string $expectedCertificateDer certificate of the company that must have signed */
    public function validate(string $signedXml, string $expectedCertificateDer): void
    {
        $document = $this->load($signedXml);
        $xpath = $this->xpath($document);
        $signatures = $xpath->query('/*/ds:Signature');
        if ($signatures === false || $signatures->length !== 1) {
            throw $this->invalid('SIGNATURE_MISSING', 'El comprobante debe contener exactamente una firma ds:Signature.');
        }
        /** @var DOMElement $signature */
        $signature = $signatures->item(0);

        $signedInfo = $this->single($xpath, 'ds:SignedInfo', $signature, 'SIGNED_INFO_MISSING');
        if ($this->algorithm($xpath, 'ds:CanonicalizationMethod', $signedInfo) !== self::C14N
            || $this->algorithm($xpath, 'ds:SignatureMethod', $signedInfo) !== self::RSA_SHA1) {
            throw $this->invalid('ALGORITHM_NOT_ALLOWED', 'La firma debe usar canonicalización C14N y RSA-SHA1 (ficha técnica, tabla 7).');
        }

        $certificateDer = $this->keyInfoCertificate($xpath, $signature);
        if ($certificateDer !== $expectedCertificateDer) {
            throw $this->invalid('CERTIFICATE_MISMATCH', 'La firma no fue hecha con el certificado activo de la empresa.');
        }

        $covered = $this->verifyReferences($document, $xpath, $signedInfo);
        foreach (['comprobante' => 'REFERENCE_COMPROBANTE_MISSING', 'signedProperties' => 'REFERENCE_SIGNED_PROPERTIES_MISSING', 'keyInfo' => 'REFERENCE_KEY_INFO_MISSING'] as $part => $reason) {
            if (! $covered[$part]) {
                throw $this->invalid($reason, 'La firma XAdES-BES no cubre todas las partes obligatorias del comprobante.');
            }
        }

        $this->verifySigningCertificate($xpath, $signature, $certificateDer);
        $this->verifySignatureValue($xpath, $signature, $signedInfo, $certificateDer);
    }

    /** @return array{comprobante: bool, signedProperties: bool, keyInfo: bool} */
    private function verifyReferences(DOMDocument $document, DOMXPath $xpath, DOMElement $signedInfo): array
    {
        $covered = ['comprobante' => false, 'signedProperties' => false, 'keyInfo' => false];
        foreach ($xpath->query('ds:Reference', $signedInfo) as $reference) {
            if (! $reference instanceof DOMElement) {
                continue;
            }
            $uri = $reference->getAttribute('URI');
            if (! str_starts_with($uri, '#') || strlen($uri) < 2) {
                throw $this->invalid('REFERENCE_URI_NOT_ALLOWED', 'Sólo se aceptan referencias internas del comprobante.');
            }
            $transforms = [];
            foreach ($xpath->query('ds:Transforms/ds:Transform', $reference) as $transform) {
                $transforms[] = $transform instanceof DOMElement ? $transform->getAttribute('Algorithm') : '';
            }
            if (array_diff($transforms, [self::ENVELOPED]) !== []) {
                throw $this->invalid('TRANSFORM_NOT_ALLOWED', 'La firma contiene transformaciones no permitidas.');
            }

            $target = $this->elementById($xpath, substr($uri, 1));
            $canonical = in_array(self::ENVELOPED, $transforms, true) ? $this->withoutSignature($document, substr($uri, 1)) : $target->C14N(false, false);
            $algorithm = self::DIGESTS[$this->algorithm($xpath, 'ds:DigestMethod', $reference)] ?? null;
            $expected = base64_decode(preg_replace('/\s+/', '', (string) $xpath->evaluate('string(ds:DigestValue)', $reference)), true);
            if ($algorithm === null || $canonical === false || $expected === false || ! hash_equals($expected, hash($algorithm, $canonical, true))) {
                throw $this->invalid('DIGEST_MISMATCH', 'El contenido firmado no coincide con su resumen criptográfico ('.$uri.').');
            }

            $isRoot = $target->isSameNode($document->documentElement);
            $covered['comprobante'] = $covered['comprobante'] || ($isRoot && $target->getAttribute('id') === 'comprobante' && in_array(self::ENVELOPED, $transforms, true));
            $covered['signedProperties'] = $covered['signedProperties'] || ($reference->getAttribute('Type') === self::SIGNED_PROPERTIES_TYPE
                && $target->namespaceURI === self::XADES && $target->localName === 'SignedProperties');
            $covered['keyInfo'] = $covered['keyInfo'] || ($target->namespaceURI === self::DS && $target->localName === 'KeyInfo');
        }

        return $covered;
    }

    private function verifySigningCertificate(DOMXPath $xpath, DOMElement $signature, string $certificateDer): void
    {
        $certDigest = $this->single($xpath, 'ds:Object/xades:QualifyingProperties/xades:SignedProperties/xades:SignedSignatureProperties/xades:SigningCertificate/xades:Cert/xades:CertDigest', $signature, 'SIGNING_CERTIFICATE_MISSING');
        $algorithm = self::DIGESTS[$this->algorithm($xpath, 'ds:DigestMethod', $certDigest)] ?? null;
        $expected = base64_decode(preg_replace('/\s+/', '', (string) $xpath->evaluate('string(ds:DigestValue)', $certDigest)), true);
        if ($algorithm === null || $expected === false || ! hash_equals($expected, hash($algorithm, $certificateDer, true))) {
            throw $this->invalid('SIGNING_CERTIFICATE_MISMATCH', 'El certificado declarado en SignedProperties no es el certificado firmante.');
        }
    }

    private function verifySignatureValue(DOMXPath $xpath, DOMElement $signature, DOMElement $signedInfo, string $certificateDer): void
    {
        $signatureValue = base64_decode(preg_replace('/\s+/', '', (string) $xpath->evaluate('string(ds:SignatureValue)', $signature)), true);
        $certificate = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($certificateDer), 64, "\n")."-----END CERTIFICATE-----\n";
        $canonical = $signedInfo->C14N(false, false);
        if ($signatureValue === false || $canonical === false || openssl_verify($canonical, $signatureValue, $certificate, OPENSSL_ALGO_SHA1) !== 1) {
            throw $this->invalid('SIGNATURE_VALUE_INVALID', 'El valor de la firma no corresponde a SignedInfo y al certificado firmante.');
        }
    }

    private function keyInfoCertificate(DOMXPath $xpath, DOMElement $signature): string
    {
        $encoded = preg_replace('/\s+/', '', (string) $xpath->evaluate('string(ds:KeyInfo/ds:X509Data/ds:X509Certificate)', $signature));
        $der = base64_decode((string) $encoded, true);
        if ($der === false || $der === '' || @openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split((string) $encoded, 64, "\n")."-----END CERTIFICATE-----\n") === false) {
            throw $this->invalid('KEY_INFO_CERTIFICATE_INVALID', 'La firma no incluye un certificado X.509 legible en KeyInfo.');
        }

        return $der;
    }

    /** Enveloped-signature transform: the referenced element, in its document context, without the signature. */
    private function withoutSignature(DOMDocument $document, string $id): string|false
    {
        $copy = $document->cloneNode(true);
        $copyXpath = $this->xpath($copy);
        $target = $this->elementById($copyXpath, $id);
        foreach (iterator_to_array($copyXpath->query('.//ds:Signature', $target)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        return $target->C14N(false, false);
    }

    private function elementById(DOMXPath $xpath, string $id): DOMElement
    {
        $matches = $xpath->query('//*[@Id='.$this->literal($id).' or @id='.$this->literal($id).']');
        if ($matches === false || $matches->length !== 1 || ! $matches->item(0) instanceof DOMElement) {
            throw $this->invalid('REFERENCE_TARGET_INVALID', 'Cada referencia de la firma debe apuntar a un único elemento del comprobante.');
        }

        return $matches->item(0);
    }

    private function single(DOMXPath $xpath, string $query, DOMElement $context, string $reason): DOMElement
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            throw $this->invalid($reason, 'La estructura de la firma XAdES-BES está incompleta.');
        }

        return $nodes->item(0);
    }

    private function algorithm(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        return (string) $xpath->evaluate('string('.$query.'/@Algorithm)', $context);
    }

    private function load(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->doctype !== null || ! $document->documentElement instanceof DOMElement) {
            throw $this->invalid('XML_UNREADABLE', 'El XML firmado no es un documento válido.');
        }

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::DS);
        $xpath->registerNamespace('xades', self::XADES);

        return $xpath;
    }

    private function literal(string $value): string
    {
        return str_contains($value, "'") ? '"'.$value.'"' : "'".$value."'";
    }

    private function invalid(string $reason, string $message): SignatureValidationException
    {
        return new SignatureValidationException($reason, $message);
    }
}
