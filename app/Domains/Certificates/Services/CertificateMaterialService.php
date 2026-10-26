<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\DTOs\CertificateMaterial;
use App\Domains\Certificates\Models\DigitalCertificate;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Opens the company certificate for exactly one operation. The PKCS#12 is copied to a private
 * temporary file (0600) that is always removed, and the password is only decrypted in memory.
 */
final class CertificateMaterialService
{
    /**
     * @template T
     *
     * @param  Closure(CertificateMaterial): T  $callback
     * @return T
     */
    public function using(DigitalCertificate $certificate, Closure $callback): mixed
    {
        if (blank($certificate->storage_path) || blank($certificate->password_ciphertext)) {
            throw new RuntimeException('CERTIFICATE_MATERIAL_UNAVAILABLE');
        }
        $contents = Storage::disk('private')->get($certificate->storage_path);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('CERTIFICATE_MATERIAL_UNAVAILABLE');
        }
        try {
            $password = Crypt::decryptString($certificate->password_ciphertext);
        } catch (DecryptException) {
            throw new RuntimeException('CERTIFICATE_MATERIAL_UNAVAILABLE');
        }

        $parts = [];
        if (! @openssl_pkcs12_read($contents, $parts, $password) || ! isset($parts['cert'], $parts['pkey'])
            || ! @openssl_x509_export($parts['cert'], $pem)) {
            throw new RuntimeException('CERTIFICATE_MATERIAL_UNAVAILABLE');
        }
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        if ($der === false || $der === '') {
            throw new RuntimeException('CERTIFICATE_MATERIAL_UNAVAILABLE');
        }

        $path = tempnam(sys_get_temp_dir(), 'facsto-p12-');
        if ($path === false) {
            throw new RuntimeException('CERTIFICATE_MATERIAL_UNAVAILABLE');
        }
        try {
            chmod($path, 0600);
            file_put_contents($path, $contents);

            return $callback(new CertificateMaterial($path, $password, $der));
        } finally {
            @unlink($path);
        }
    }
}
