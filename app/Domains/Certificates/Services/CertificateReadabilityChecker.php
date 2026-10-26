<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\Models\DigitalCertificate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Proves the stored PKCS#12 still opens with its stored password and contains a private key. */
final class CertificateReadabilityChecker
{
    public function isReadable(DigitalCertificate $certificate): bool
    {
        if (blank($certificate->storage_path) || blank($certificate->password_ciphertext)) {
            return false;
        }

        try {
            $contents = Storage::disk('private')->get($certificate->storage_path);
            $parts = [];

            return is_string($contents)
                && @openssl_pkcs12_read($contents, $parts, Crypt::decryptString($certificate->password_ciphertext))
                && isset($parts['cert'], $parts['pkey']);
        } catch (Throwable) {
            return false;
        }
    }
}
