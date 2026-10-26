<?php

namespace App\Domains\Certificates\Actions;

use App\Domains\Certificates\DTOs\CertificateInspectionResult;
use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Certificates\Services\CertificateInspector;
use App\Domains\Certificates\Services\CertificateStorageService;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
        $inspection = $this->inspector->inspect($file->getRealPath(), $password);
        $status = $this->status($inspection, $company);

        if ($status !== CertificateStatus::VALID) {
            // Keep metadata for support, but never custody a key that cannot sign for this company.
            $certificate = $this->recordRejection($company, $inspection, $status, $actor);
            throw $this->rejection($certificate, $company);
        }

        $certificatePublicId = (string) Str::uuid7();
        $path = $this->storage->store($file, $company->public_id, $certificatePublicId);

        try {
            return DB::transaction(function () use ($company, $actor, $inspection, $password, $certificatePublicId, $path): DigitalCertificate {
                $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $certificate = $this->newCertificate($inspection, CertificateStatus::VALID);
                $certificate->forceFill([
                    'storage_path' => $path,
                    'password_ciphertext' => Crypt::encryptString($password),
                    'is_active' => true,
                ]);
                $certificate->public_id = $certificatePublicId;
                $company->digitalCertificates()
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (DigitalCertificate $active) => $active->forceFill(['is_active' => false])->save());
                $company->digitalCertificates()->save($certificate);

                $this->audit->record($actor, $company, 'certificate.uploaded', $certificate, null, $this->auditPayload($certificate));

                return $certificate->refresh();
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);
            throw $exception;
        }
    }

    private function status(CertificateInspectionResult $inspection, Company $company): CertificateStatus
    {
        $now = Carbon::now();

        return match (true) {
            $inspection->validUntil->lessThanOrEqualTo($now) => CertificateStatus::EXPIRED,
            $inspection->validFrom->greaterThan($now) => CertificateStatus::NOT_YET_VALID,
            $inspection->fiscalIdentification === null => CertificateStatus::RUC_NOT_FOUND,
            $inspection->fiscalIdentification !== $company->ruc => CertificateStatus::RUC_MISMATCH,
            default => CertificateStatus::VALID,
        };
    }

    private function newCertificate(CertificateInspectionResult $inspection, CertificateStatus $status): DigitalCertificate
    {
        return (new DigitalCertificate)->forceFill([
            'issued_to' => $inspection->subjectName,
            'subject_identification' => $inspection->subjectIdentification,
            'subject_ruc' => $inspection->fiscalIdentification,
            'issuer' => $inspection->issuer,
            'serial_number' => $inspection->serialNumber,
            'valid_from' => $inspection->validFrom,
            'valid_until' => $inspection->validUntil,
            'status' => $status,
            'validation_error_code' => $status === CertificateStatus::VALID ? null : $status->value,
            'validated_at' => now(),
            'last_checked_at' => now(),
            'is_active' => false,
        ]);
    }

    private function recordRejection(Company $company, CertificateInspectionResult $inspection, CertificateStatus $status, User $actor): DigitalCertificate
    {
        return DB::transaction(function () use ($company, $inspection, $status, $actor): DigitalCertificate {
            $certificate = $this->newCertificate($inspection, $status);
            $company->digitalCertificates()->save($certificate);
            $this->audit->record($actor, $company, 'certificate.rejected', $certificate, null, $this->auditPayload($certificate));

            return $certificate;
        });
    }

    private function rejection(DigitalCertificate $certificate, Company $company): ApiException
    {
        [$code, $message] = match ($certificate->status) {
            CertificateStatus::EXPIRED => [ApiErrorCode::CERTIFICATE_EXPIRED, 'El certificado está caducado. Carga un certificado vigente.'],
            CertificateStatus::NOT_YET_VALID => [ApiErrorCode::CERTIFICATE_NOT_YET_VALID, 'El certificado todavía no está vigente. Revisa su fecha de inicio.'],
            CertificateStatus::RUC_NOT_FOUND => [ApiErrorCode::CERTIFICATE_RUC_NOT_FOUND, 'No se pudo identificar un RUC en el certificado. Carga un certificado de firma electrónica que incluya el RUC de la empresa para emitir comprobantes electrónicos.'],
            default => [ApiErrorCode::CERTIFICATE_RUC_MISMATCH, "El certificado no corresponde al RUC de esta empresa. La empresa está configurada con el RUC {$company->ruc}, pero el certificado cargado pertenece a una identidad fiscal diferente. Carga el certificado correspondiente para poder emitir comprobantes electrónicos."],
        };

        return new ApiException($code, $message, 422, [
            'certificatePublicId' => $certificate->public_id,
            'companyRuc' => $company->ruc,
            'certificateRuc' => $certificate->subject_ruc,
        ]);
    }

    private function auditPayload(DigitalCertificate $certificate): array
    {
        return [
            'issuedTo' => $certificate->issued_to,
            'issuer' => $certificate->issuer,
            'subjectRuc' => $certificate->subject_ruc,
            'validUntil' => $certificate->valid_until?->toIso8601String(),
            'status' => $certificate->status->value,
            'isActive' => $certificate->is_active,
        ];
    }
}
