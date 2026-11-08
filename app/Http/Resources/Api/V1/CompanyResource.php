<?php

namespace App\Http\Resources\Api\V1;

use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Companies\Access\CompanyAccess;
use App\Domains\Companies\Fiscal\Services\ElectronicInvoicingRequirements;
use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Services\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;
        $access = app(CompanyAccess::class);
        $permissions = $access->permissions($request->user(), $company);
        $certificate = $company->digitalCertificates()->where('is_active', true)->first();

        return [
            'publicId' => $company->public_id,
            'slug' => $company->slug,
            'ruc' => $company->ruc,
            'legalName' => $company->legal_name,
            'commercialName' => $company->commercial_name,
            'mainAddress' => $company->main_address,
            'email' => $company->email,
            'phone' => $company->phone,
            'hasLogo' => $company->logo_path !== null,
            'accountingRequired' => $company->fiscalSettings?->accounting_required ?? false,
            'sriEnvironment' => $company->fiscalSettings?->sri_environment?->value,
            'status' => $company->status->value,
            // Rol fijo Administrador: configuración del sistema. Los permisos «módulo.acción» deciden el resto.
            'isAdministrator' => $access->isAdministrator($request->user(), $company),
            'permissions' => $permissions,
            // Expose only the readiness boolean needed by this view, not certificate notices/metadata.
            'activeCertificate' => $certificate ? [
                'usable' => $certificate->status === CertificateStatus::VALID
                    && $certificate->subject_ruc !== null
                    && $certificate->subject_ruc === $company->ruc
                    && $certificate->valid_from?->lessThanOrEqualTo(now())
                    && $certificate->valid_until?->greaterThan(now()),
            ] : null,
            // «Activar facturación electrónica»: the switch and its checklist.
            'electronicInvoicing' => [
                'enabled' => (bool) $company->fiscalSettings?->sri_submission_enabled,
                'requirements' => app(ElectronicInvoicingRequirements::class)->check($company),
            ],
            // Módulos contratados (derechos de Condore Platform) y lo que el usuario puede hacer en cada uno.
            'modules' => app(ModuleRegistry::class)->forCompany($company, $permissions),
        ];
    }
}
