<?php

namespace Tests\Support;

use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Certificates\Services\CertificateInspector;
use App\Domains\Companies\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/** Real PKCS#12 files for tests; the holder RUC travels in the X.509 extension documented by the SRI. */
trait CreatesTestCertificates
{
    /** @var array<string, string> generated files, keyed by RUC/validity/password (RSA keygen is slow) */
    private static array $pkcs12Cache = [];

    protected function pkcs12Contents(?string $ruc, string $password = 'test-password', int $days = 30): string
    {
        $key = ($ruc ?? 'none').'|'.$days.'|'.$password;
        if (isset(self::$pkcs12Cache[$key])) {
            return self::$pkcs12Cache[$key];
        }

        $config = tempnam(sys_get_temp_dir(), 'facsto-cnf');
        $extension = $ruc === null ? '' : CertificateInspector::RUC_EXTENSION_OIDS[0]."=ASN1:PRINTABLESTRING:{$ruc}\n";
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[facsto_ext]\nbasicConstraints=CA:FALSE\n{$extension}");
        $options = ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'facsto_ext'];
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $config]);
        $request = openssl_csr_new(['commonName' => 'FACSTO Test Certificate'], $privateKey, $options);
        $certificate = openssl_csr_sign($request, null, $privateKey, $days, $options);
        openssl_pkcs12_export($certificate, $contents, $privateKey, $password);
        unlink($config);

        return self::$pkcs12Cache[$key] = $contents;
    }

    protected function pkcs12Upload(?string $ruc, string $password = 'test-password', int $days = 30): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('facsto-test.p12', $this->pkcs12Contents($ruc, $password, $days));
    }

    /** Active, readable certificate of the company RUC, as an upload would leave it. */
    protected function installReadyCertificate(Company $company): DigitalCertificate
    {
        $path = 'tests/'.$company->public_id.'/certificate.p12';
        Storage::disk('private')->put($path, $this->pkcs12Contents($company->ruc));

        return $company->digitalCertificates()->create([
            'storage_path' => $path, 'password_ciphertext' => Crypt::encryptString('test-password'),
            'issued_to' => 'FACSTO test', 'subject_ruc' => $company->ruc, 'issuer' => 'Test issuer', 'serial_number' => 'TEST-1',
            'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(), 'status' => 'VALID',
            'validated_at' => now(), 'last_checked_at' => now(), 'is_active' => true,
        ]);
    }
}
