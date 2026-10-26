<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\DTOs\CertificateInspectionResult;
use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Carbon;

final class CertificateInspector
{
    /**
     * X.509 extensions where Ecuadorian certification entities store the holder's RUC.
     * Only the OID documented in the SRI technical sheet (v2.34, p. 141) is listed; add others
     * only after confirming them against a real certificate of that entity.
     */
    public const RUC_EXTENSION_OIDS = ['1.3.6.1.4.1.37947.3.11'];

    public function __construct(private readonly EcuadorRucValidator $rucValidator) {}

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
            fiscalIdentification: $this->fiscalIdentification($metadata['extensions'] ?? []),
            issuer: $metadata['issuer']['CN'] ?? $metadata['issuer']['commonName'] ?? 'No especificado',
            serialNumber: (string) ($metadata['serialNumberHex'] ?? $metadata['serialNumber'] ?? ''),
            validFrom: Carbon::createFromTimestamp((int) $metadata['validFrom_time_t'], $timezone),
            validUntil: Carbon::createFromTimestamp((int) $metadata['validTo_time_t'], $timezone),
            hasPrivateKey: true,
        );
    }

    /** Returns the holder RUC only when a recognized extension carries a structurally valid one. */
    private function fiscalIdentification(array $extensions): ?string
    {
        foreach (self::RUC_EXTENSION_OIDS as $oid) {
            if (! isset($extensions[$oid]) || ! is_string($extensions[$oid])) {
                continue;
            }
            $value = $this->derStringContent($extensions[$oid]);
            if (preg_match('/^\d{13}$/', $value) === 1 && $this->rucValidator->isStructurallyValid($value)) {
                return $value;
            }
        }

        return null;
    }

    /** Unknown extensions arrive as DER: tag (PrintableString/UTF8String/IA5String), length, content. */
    private function derStringContent(string $raw): string
    {
        if (strlen($raw) >= 2 && in_array(ord($raw[0]), [0x13, 0x0C, 0x16], true) && ord($raw[1]) === strlen($raw) - 2) {
            return substr($raw, 2);
        }

        return trim($raw);
    }

    private function invalid(string $reason, string $message): ApiException
    {
        return new ApiException(ApiErrorCode::CERTIFICATE_INVALID, $message, 422, ['reason' => $reason]);
    }
}
