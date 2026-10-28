<?php

namespace App\Domains\Certificates\Services;

use App\Domains\Certificates\DTOs\AcceptedCertificate;
use App\Domains\Certificates\DTOs\CertificateIdentity;
use App\Domains\Certificates\DTOs\CertificateInspectionResult;
use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Certificates\Models\DigitalCertificate;
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

/**
 * The single place where a P12 is accepted for a company: inspection → identity for the given RUC →
 * validity → private storage → activation. Used by onboarding and by "Datos de la empresa".
 */
final class CertificateAcceptanceService
{
    public function __construct(
        private readonly CertificateInspector $inspector,
        private readonly CertificateIdentityResolver $identities,
        private readonly CertificateStorageService $storage,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Checks the certificate against $ruc (the RUC being saved, not necessarily the stored one) and keeps
     * the file privately. A rejection is recorded with its diagnosis and the key is never kept.
     */
    public function accept(Company $company, UploadedFile $file, #[\SensitiveParameter] string $password, string $ruc, User $actor): AcceptedCertificate
    {
        $inspection = $this->inspector->inspect($file->getRealPath(), $password);
        $identity = $this->identities->resolve($inspection->identificationCandidates, $ruc);
        $status = $this->status($inspection, $identity);

        if ($status !== CertificateStatus::VALID) {
            $certificate = $this->recordRejection($company, $inspection, $identity, $status, $actor);
            throw $this->rejection($certificate, $ruc);
        }

        $publicId = (string) Str::uuid7();

        return new AcceptedCertificate($inspection, $identity, $publicId, $this->storage->store($file, $company->public_id, $publicId), $password);
    }

    /** Must run inside the transaction that holds the company lock; replaces the active certificate. */
    public function activate(Company $lockedCompany, AcceptedCertificate $accepted, User $actor): DigitalCertificate
    {
        $certificate = $this->newCertificate($accepted->inspection, $accepted->identity, CertificateStatus::VALID);
        $certificate->forceFill([
            'storage_path' => $accepted->storagePath,
            'password_ciphertext' => Crypt::encryptString($accepted->password),
            'is_active' => true,
        ]);
        $certificate->public_id = $accepted->publicId;
        $lockedCompany->digitalCertificates()
            ->where('is_active', true)
            ->lockForUpdate()
            ->get()
            ->each(fn (DigitalCertificate $active) => $active->forceFill(['is_active' => false])->save());
        $lockedCompany->digitalCertificates()->save($certificate);

        $this->audit->record($actor, $lockedCompany, 'certificate.uploaded', $certificate, null, $this->auditPayload($certificate));

        return $certificate->refresh();
    }

    /** Removes the stored file when the surrounding save did not commit. */
    public function discard(AcceptedCertificate $accepted): void
    {
        $this->storage->delete($accepted->storagePath);
    }

    private function status(CertificateInspectionResult $inspection, CertificateIdentity $identity): CertificateStatus
    {
        $now = Carbon::now();

        return match (true) {
            $inspection->validUntil->lessThanOrEqualTo($now) => CertificateStatus::EXPIRED,
            $inspection->validFrom->greaterThan($now) => CertificateStatus::NOT_YET_VALID,
            ! $identity->found() => CertificateStatus::RUC_NOT_FOUND,
            ! $identity->matchesCompany => CertificateStatus::RUC_MISMATCH,
            default => CertificateStatus::VALID,
        };
    }

    private function newCertificate(CertificateInspectionResult $inspection, CertificateIdentity $identity, CertificateStatus $status): DigitalCertificate
    {
        return (new DigitalCertificate)->forceFill([
            'issued_to' => $inspection->subjectName,
            'subject_identification' => $inspection->subjectIdentification,
            'subject_ruc' => $identity->ruc,
            'identification_source' => $identity->source,
            'identification_candidates' => $inspection->identificationCandidates,
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

    private function recordRejection(Company $company, CertificateInspectionResult $inspection, CertificateIdentity $identity, CertificateStatus $status, User $actor): DigitalCertificate
    {
        return DB::transaction(function () use ($company, $inspection, $identity, $status, $actor): DigitalCertificate {
            $certificate = $this->newCertificate($inspection, $identity, $status);
            $company->digitalCertificates()->save($certificate);
            $this->audit->record($actor, $company, 'certificate.rejected', $certificate, null, $this->auditPayload($certificate));

            return $certificate;
        });
    }

    private function rejection(DigitalCertificate $certificate, string $ruc): ApiException
    {
        [$code, $message] = match ($certificate->status) {
            CertificateStatus::EXPIRED => [ApiErrorCode::CERTIFICATE_EXPIRED, 'El certificado está caducado. Carga un certificado vigente.'],
            CertificateStatus::NOT_YET_VALID => [ApiErrorCode::CERTIFICATE_NOT_YET_VALID, 'El certificado todavía no está vigente. Revisa su fecha de inicio.'],
            CertificateStatus::RUC_NOT_FOUND => [ApiErrorCode::CERTIFICATE_RUC_NOT_FOUND, 'No se pudo identificar en el certificado el RUC de la empresa ni, para personas naturales, la cédula del titular. Si el certificado es correcto, contacta a soporte con el identificador del certificado para habilitar su entidad certificadora.'],
            default => [ApiErrorCode::CERTIFICATE_RUC_MISMATCH, "El certificado no corresponde al RUC {$ruc}: pertenece a ".($certificate->subject_ruc ? "el RUC {$certificate->subject_ruc}" : 'otra identidad fiscal').'. Carga la firma electrónica de este RUC.'],
        };

        return new ApiException($code, $message, 422, [
            'certificatePublicId' => $certificate->public_id,
            'companyRuc' => $ruc,
            'certificateRuc' => $certificate->subject_ruc,
            // Where the certificate carries identifications, for support (values stay in the record).
            'identificationSources' => array_values(array_unique(array_column($certificate->identification_candidates ?? [], 'source'))),
        ]);
    }

    private function auditPayload(DigitalCertificate $certificate): array
    {
        return [
            'issuedTo' => $certificate->issued_to,
            'issuer' => $certificate->issuer,
            'subjectRuc' => $certificate->subject_ruc,
            'identificationSource' => $certificate->identification_source,
            'identificationCandidates' => $certificate->identification_candidates,
            'validUntil' => $certificate->valid_until?->toIso8601String(),
            'status' => $certificate->status->value,
            'isActive' => $certificate->is_active,
        ];
    }
}
