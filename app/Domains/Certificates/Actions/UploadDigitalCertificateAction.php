<?php

namespace App\Domains\Certificates\Actions;

use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Certificates\Services\CertificateInspector;
use App\Domains\Certificates\Services\CertificateStorageService;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class UploadDigitalCertificateAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CertificateInspector $inspector,
        private readonly CertificateStorageService $storage,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(UploadedFile $file, string $password, User $actor): DigitalCertificate
    {
        $company = $this->context->company();
        $metadata = $this->inspector->inspect($file->getRealPath(), $password);
        $certificatePublicId = (string) Str::uuid7();
        $path = $this->storage->store($file, $company->public_id, $certificatePublicId);

        try {
            return DB::transaction(function () use ($company, $actor, $metadata, $password, $certificatePublicId, $path): DigitalCertificate {
                $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $certificate = new DigitalCertificate([
                    'storage_path' => $path,
                    'password_ciphertext' => Crypt::encryptString($password),
                    'issued_to' => $metadata['issued_to'],
                    'issuer' => $metadata['issuer'],
                    'serial_number' => $metadata['serial_number'],
                    'valid_from' => $metadata['valid_from'],
                    'valid_until' => $metadata['valid_until'],
                    'status' => $metadata['status'],
                    'validated_at' => now(),
                    'last_checked_at' => now(),
                    'is_active' => $metadata['status'] === CertificateStatus::VALID,
                ]);
                $certificate->public_id = $certificatePublicId;
                if ($metadata['status'] === CertificateStatus::VALID) {
                    $company->digitalCertificates()
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->get()
                        ->each(fn (DigitalCertificate $active) => $active->forceFill(['is_active' => false])->save());
                }
                $company->digitalCertificates()->save($certificate);

                $this->audit->record($actor, $company, 'certificate.uploaded', $certificate, null, [
                    'issuedTo' => $certificate->issued_to,
                    'issuer' => $certificate->issuer,
                    'validUntil' => $certificate->valid_until?->toIso8601String(),
                    'status' => $certificate->status->value,
                    'isActive' => $certificate->is_active,
                ]);

                return $certificate->refresh();
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);
            throw $exception;
        }
    }
}
