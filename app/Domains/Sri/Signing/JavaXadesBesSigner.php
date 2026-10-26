<?php

namespace App\Domains\Sri\Signing;

use App\Domains\Certificates\DTOs\CertificateMaterial;
use App\Domains\Sri\Signing\Contracts\XmlSigner;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs tools/xades-signer (JDK XMLDSig). The P12 password goes only through stdin: never in the
 * command line, the environment, a file or a log line.
 */
final class JavaXadesBesSigner implements XmlSigner
{
    public function sign(string $unsignedXml, CertificateMaterial $material): string
    {
        $jar = (string) config('sri.signer.jar');
        if (! is_file($jar)) {
            throw new XmlSigningException('SIGNER_NOT_INSTALLED', 'El firmador electrónico no está instalado en el servidor.');
        }

        $input = tempnam(sys_get_temp_dir(), 'facsto-xml-');
        if ($input === false) {
            throw new XmlSigningException('SIGNER_IO_FAILED', 'No se pudo preparar el comprobante para firmarlo.');
        }

        try {
            file_put_contents($input, $unsignedXml);
            $process = new Process([
                (string) config('sri.signer.java_binary', 'java'),
                '-Dcom.sun.org.apache.xml.internal.security.ignoreLineBreaks=true',
                '-jar', $jar,
                '--p12', $material->pkcs12Path,
                '--input', $input,
            ]);
            $process->setTimeout((float) config('sri.signer.timeout', 60));
            $process->setInput($material->password);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new XmlSigningException('SIGNER_TIMEOUT', 'El firmador electrónico no respondió a tiempo.');
            }

            if (! $process->isSuccessful()) {
                // stderr carries "REASON: detail"; only the reason is kept.
                $reason = strtok(trim($process->getErrorOutput()), ':') ?: 'SIGNING_FAILED';
                $reason = preg_match('/^[A-Z_]{3,64}$/', $reason) === 1 ? $reason : 'SIGNING_FAILED';
                Log::warning('XAdES-BES signer rejected the document.', ['reason' => $reason, 'exit_code' => $process->getExitCode()]);

                throw new XmlSigningException($reason, match ($reason) {
                    'KEYSTORE_UNREADABLE' => 'No se pudo abrir el certificado electrónico con su contraseña.',
                    'XML_UNREADABLE' => 'El XML del comprobante no es válido para firmarse.',
                    default => 'No se pudo firmar electrónicamente el comprobante.',
                });
            }

            $signed = $process->getOutput();
            if ($signed === '') {
                throw new XmlSigningException('SIGNING_FAILED', 'El firmador electrónico no devolvió el comprobante firmado.');
            }

            return $signed;
        } finally {
            @unlink($input);
        }
    }
}
