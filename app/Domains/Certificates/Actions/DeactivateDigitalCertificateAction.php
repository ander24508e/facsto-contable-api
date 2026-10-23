<?php

namespace App\Domains\Certificates\Actions;

use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class DeactivateDigitalCertificateAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(DigitalCertificate $certificate, User $actor): DigitalCertificate
    {
        $company = $this->context->company();
        if ($certificate->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::CERTIFICATE_INVALID, 'El certificado no pertenece a la empresa activa.', 404);
        }

        $certificate->forceFill(['is_active' => false])->save();
        $this->audit->record($actor, $company, 'certificate.deactivated', $certificate, ['isActive' => true], ['isActive' => false]);

        return $certificate->refresh();
    }
}
