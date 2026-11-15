<?php

namespace Tests\Support;

use App\Domains\Certificates\DTOs\CertificateMaterial;
use App\Domains\Sri\Signing\Contracts\XmlSigner;
use App\Domains\Sri\Signing\XmlSigningException;
use DOMDocument;
use DOMElement;

/**
 * Tests only: the same XAdES-BES the Java signer makes (C14N, RSA-SHA1, references to the comprobante, the signed
 * properties and KeyInfo), written with PHP's OpenSSL. TestCase uses it when tools/xades-signer is not installed, so
 * the suite does not depend on the JVM; with the jar present the real signer runs. SignatureValidator and the XSD check
 * it exactly as they check the real one.
 */
final class PhpXadesBesTestSigner implements XmlSigner
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    public function sign(string $unsignedXml, CertificateMaterial $material): string
    {
        $store = [];
        if (! openssl_pkcs12_read((string) file_get_contents($material->pkcs12Path), $store, $material->password)) {
            throw new XmlSigningException('KEYSTORE_UNREADABLE', 'No se pudo abrir el certificado electrónico con su contraseña.');
        }
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        if (! $document->loadXML($unsignedXml, LIBXML_NONET) || ! $document->documentElement instanceof DOMElement) {
            throw new XmlSigningException('XML_UNREADABLE', 'El XML del comprobante no es válido para firmarse.');
        }
        $root = $document->documentElement;
        $comprobanteDigest = $this->digest((string) $root->C14N(false, false));

        $id = 'Signature'.bin2hex(random_bytes(4));
        $signature = $this->ds($document, 'Signature', ['Id' => $id]);
        $signature->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etsi', self::XADES);
        $root->appendChild($signature);

        $signedInfo = $signature->appendChild($this->ds($document, 'SignedInfo'));
        $signedInfo->appendChild($this->ds($document, 'CanonicalizationMethod', ['Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315']));
        $signedInfo->appendChild($this->ds($document, 'SignatureMethod', ['Algorithm' => self::DS.'rsa-sha1']));
        $this->reference($document, $signedInfo, '#'.$id.'-SignedProperties', ['Type' => 'http://uri.etsi.org/01903#SignedProperties']);
        $this->reference($document, $signedInfo, '#'.$id.'-KeyInfo');
        $this->reference($document, $signedInfo, '#comprobante', [], true)->textContent = $comprobanteDigest;

        $signature->appendChild($this->ds($document, 'SignatureValue'));
        $der = $material->certificateDer;
        $keyInfo = $signature->appendChild($this->ds($document, 'KeyInfo', ['Id' => $id.'-KeyInfo']));
        $keyInfo->appendChild($this->ds($document, 'X509Data'))->appendChild($this->ds($document, 'X509Certificate', [], base64_encode($der)));

        $x509 = (array) openssl_x509_parse((string) $store['cert']);
        $issuer = implode(',', array_map(fn ($key, $value) => $key.'='.(is_array($value) ? implode('+', $value) : $value), array_keys((array) ($x509['issuer'] ?? [])), (array) ($x509['issuer'] ?? [])));
        $object = $signature->appendChild($this->ds($document, 'Object'));
        $qualifying = $object->appendChild($this->xades($document, 'QualifyingProperties', ['Target' => '#'.$id]));
        $properties = $qualifying->appendChild($this->xades($document, 'SignedProperties', ['Id' => $id.'-SignedProperties']));
        $signatureProperties = $properties->appendChild($this->xades($document, 'SignedSignatureProperties'));
        $signatureProperties->appendChild($this->xades($document, 'SigningTime', [], now()->toIso8601String()));
        $cert = $signatureProperties->appendChild($this->xades($document, 'SigningCertificate'))->appendChild($this->xades($document, 'Cert'));
        $certDigest = $cert->appendChild($this->xades($document, 'CertDigest'));
        $certDigest->appendChild($this->ds($document, 'DigestMethod', ['Algorithm' => self::DS.'sha1']));
        $certDigest->appendChild($this->ds($document, 'DigestValue', [], $this->digest($der)));
        $serial = $cert->appendChild($this->xades($document, 'IssuerSerial'));
        $serial->appendChild($this->ds($document, 'X509IssuerName', [], $issuer));
        $serial->appendChild($this->ds($document, 'X509SerialNumber', [], (string) ($x509['serialNumber'] ?? '0')));

        // Digests over the parsed document (inherited namespaces included), as the validator recomputes them.
        $parsed = new DOMDocument;
        $parsed->preserveWhiteSpace = true;
        $parsed->loadXML((string) $document->saveXML(), LIBXML_NONET);
        $xpath = new \DOMXPath($parsed);
        $xpath->registerNamespace('ds', self::DS);
        foreach (['-SignedProperties', '-KeyInfo'] as $suffix) {
            $target = $xpath->query("//*[@Id='{$id}{$suffix}']")->item(0);
            $xpath->query("//ds:Reference[@URI='#{$id}{$suffix}']/ds:DigestValue")->item(0)->textContent = $this->digest((string) $target->C14N(false, false));
        }
        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $value = '';
        if (! openssl_sign((string) $signedInfo->C14N(false, false), $value, $store['pkey'], OPENSSL_ALGO_SHA1)) {
            throw new XmlSigningException('SIGNING_FAILED', 'No se pudo firmar electrónicamente el comprobante.');
        }
        $xpath->query('//ds:SignatureValue')->item(0)->textContent = base64_encode($value);

        return (string) $parsed->saveXML();
    }

    /** Appends a ds:Reference and returns its (empty) DigestValue. */
    private function reference(DOMDocument $document, \DOMNode $signedInfo, string $uri, array $attributes = [], bool $enveloped = false): DOMElement
    {
        $reference = $signedInfo->appendChild($this->ds($document, 'Reference', ['URI' => $uri, ...$attributes]));
        if ($enveloped) {
            $reference->appendChild($this->ds($document, 'Transforms'))->appendChild($this->ds($document, 'Transform', ['Algorithm' => self::DS.'enveloped-signature']));
        }
        $reference->appendChild($this->ds($document, 'DigestMethod', ['Algorithm' => self::DS.'sha1']));

        return $reference->appendChild($this->ds($document, 'DigestValue'));
    }

    private function digest(string $data): string
    {
        return base64_encode(hash('sha1', $data, true));
    }

    private function ds(DOMDocument $document, string $name, array $attributes = [], ?string $text = null): DOMElement
    {
        return $this->element($document, self::DS, 'ds:'.$name, $attributes, $text);
    }

    private function xades(DOMDocument $document, string $name, array $attributes = [], ?string $text = null): DOMElement
    {
        return $this->element($document, self::XADES, 'etsi:'.$name, $attributes, $text);
    }

    private function element(DOMDocument $document, string $namespace, string $name, array $attributes, ?string $text): DOMElement
    {
        $element = $document->createElementNS($namespace, $name);
        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }
        if ($text !== null) {
            $element->textContent = $text;
        }

        return $element;
    }
}
