<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\DTOs\CertificateInspectionResult;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Carbon;

final class CertificateInspector
{
    /**
     * X.509 extension documented in the SRI technical sheet (v2.34, p. 141) for the holder RUC.
     * Other sources are configured in sri.certificate_identification once confirmed with a real certificate.
     */
    public const RUC_EXTENSION_OIDS = ['1.3.6.1.4.1.37947.3.11'];

    public function inspect(string $filePath, string $password): CertificateInspectionResult
    {
        $contents = file_get_contents($filePath);
        $parts = [];
        if ($contents === false || ! @openssl_pkcs12_read($contents, $parts, $password)) {
            throw $this->invalid('INVALID_FILE_OR_PASSWORD', 'El archivo P12/PFX o su contraseña no son válidos.');
        }
        if (! isset($parts['pkey']) || ! @openssl_pkey_get_private($parts['pkey'])) {
            throw $this->invalid('PRIVATE_KEY_MISSING', 'El archivo no contiene la llave privada necesaria para firmar.');
        }

        $metadata = isset($parts['cert']) ? @openssl_x509_parse($parts['cert']) : false;
        if (! is_array($metadata) || ! isset($metadata['validFrom_time_t'], $metadata['validTo_time_t'])) {
            throw $this->invalid('INVALID_CERTIFICATE', 'No se pudo leer el certificado X.509 del archivo.');
        }

        $timezone = config('app.timezone', 'UTC');

        return new CertificateInspectionResult(
            subjectName: $metadata['subject']['CN'] ?? $metadata['subject']['commonName'] ?? 'No especificado',
            subjectIdentification: isset($metadata['subject']['serialNumber']) ? (string) $metadata['subject']['serialNumber'] : null,
            identificationCandidates: $this->identificationCandidates($metadata),
            issuer: $metadata['issuer']['CN'] ?? $metadata['issuer']['commonName'] ?? 'No especificado',
            serialNumber: (string) ($metadata['serialNumberHex'] ?? $metadata['serialNumber'] ?? ''),
            validFrom: Carbon::createFromTimestamp((int) $metadata['validFrom_time_t'], $timezone),
            validUntil: Carbon::createFromTimestamp((int) $metadata['validTo_time_t'], $timezone),
            hasPrivateKey: true,
        );
    }

    /**
     * Every 10-digit (cédula) or 13-digit (RUC) number in the subject and the extensions, with its source.
     * Nothing here grants anything by itself: CertificateIdentityResolver only trusts configured sources.
     *
     * @return list<array{source: string, value: string, context: string}>
     */
    private function identificationCandidates(array $metadata): array
    {
        $fields = [];
        foreach ((array) ($metadata['subject'] ?? []) as $name => $value) {
            foreach ((array) $value as $item) {
                $fields['subject:'.$name][] = (string) $item;
            }
        }
        foreach ((array) ($metadata['extensions'] ?? []) as $name => $value) {
            if (is_string($value)) {
                $fields['extension:'.$name][] = $this->derStringContent($value);
            }
        }

        $candidates = [];
        foreach ($fields as $source => $values) {
            foreach ($values as $text) {
                preg_match_all('/(?<!\d)(\d{13}|\d{10})(?!\d)/', $text, $matches);
                foreach (array_unique($matches[1]) as $number) {
                    $candidates[] = ['source' => $source, 'value' => $number, 'context' => mb_substr($this->printable($text), 0, 200)];
                }
            }
        }

        return $candidates;
    }

    /** Unknown extensions arrive as DER: tag (PrintableString/UTF8String/IA5String), length, content. */
    private function derStringContent(string $raw): string
    {
        if (strlen($raw) >= 2 && in_array(ord($raw[0]), [0x13, 0x0C, 0x16], true) && ord($raw[1]) === strlen($raw) - 2) {
            return substr($raw, 2);
        }

        return trim($raw);
    }

    private function printable(string $text): string
    {
        return trim((string) preg_replace('/[^\P{C}\n]+/u', ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8')));
    }

    private function invalid(string $reason, string $message): ApiException
    {
        return new ApiException(ApiErrorCode::CERTIFICATE_INVALID, $message, 422, ['reason' => $reason]);
    }
}
