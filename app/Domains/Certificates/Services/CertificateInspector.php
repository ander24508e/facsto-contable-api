<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\Enums\CertificateStatus;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Carbon;

final class CertificateInspector
{
    public function inspect(string $filePath, string $password): array
    {
        $contents = file_get_contents($filePath);
        $parts = [];

        if ($contents === false || ! @openssl_pkcs12_read($contents, $parts, $password)
            || ! isset($parts['cert'], $parts['pkey'])) {
            throw new ApiException(ApiErrorCode::CERTIFICATE_INVALID, 'El archivo P12/PFX o su contraseña no son válidos.', 422);
        }

        $metadata = @openssl_x509_parse($parts['cert']);
        if (! is_array($metadata) || ! isset($metadata['validFrom_time_t'], $metadata['validTo_time_t'])) {
            throw new ApiException(ApiErrorCode::CERTIFICATE_INVALID, 'No se pudo leer el certificado del archivo.', 422);
        }

        $timezone = config('app.timezone', 'UTC');
        $validFrom = Carbon::createFromTimestamp((int) $metadata['validFrom_time_t'], $timezone);
        $validUntil = Carbon::createFromTimestamp((int) $metadata['validTo_time_t'], $timezone);
        $now = Carbon::now();
        $status = $validUntil->lessThanOrEqualTo($now)
            ? CertificateStatus::EXPIRED
            : ($validFrom->greaterThan($now) ? CertificateStatus::INVALID : CertificateStatus::VALID);

        return [
            'issued_to' => $metadata['subject']['CN'] ?? $metadata['subject']['commonName'] ?? 'No especificado',
            'issuer' => $metadata['issuer']['CN'] ?? $metadata['issuer']['commonName'] ?? 'No especificado',
            'serial_number' => (string) ($metadata['serialNumberHex'] ?? $metadata['serialNumber'] ?? ''),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'status' => $status,
        ];
    }
}
