<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Certificates\DTOs\AcceptedCertificate;
use App\Domains\Certificates\Services\CertificateAcceptanceService;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Datos de la empresa" in one save: company data, RUC and, optionally, the electronic signature.
 * Every field is optional so the client saves what it has; only the fields sent are updated. A signature
 * is checked against the RUC being saved and, if rejected, nothing is saved. Changing the RUC needs no
 * document of the current RUC still in process; the new RUC starts its own numbering (document_sequences
 * is per RUC), the previous one stays intact and electronic invoicing is switched off until reviewed.
 */
final class SaveCompanyFiscalIdentityAction
{
    private const COLUMNS = [
        'ruc' => 'ruc',
        'legalName' => 'legal_name',
        'commercialName' => 'commercial_name',
        'mainAddress' => 'main_address',
        'email' => 'email',
        'phone' => 'phone',
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly CertificateAcceptanceService $certificates,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{ruc?: ?string, legalName?: ?string, commercialName?: ?string, mainAddress?: ?string, email?: ?string, phone?: ?string} $data */
    public function execute(array $data, ?UploadedFile $certificate, #[\SensitiveParameter] ?string $password, User $actor): Company
    {
        $company = $this->context->company();
        $previousRuc = $company->ruc;
        $ruc = array_key_exists('ruc', $data) ? $data['ruc'] : $previousRuc;
        $rucChanged = $previousRuc !== $ruc;

        $this->assertRucAvailable($company, $ruc);
        if ($rucChanged && $previousRuc !== null) {
            $this->assertNoDocumentsInProcess($company);
        }
        if ($certificate !== null && $ruc === null) {
            throw ValidationException::withMessages(['ruc' => ['Escribe el RUC de la empresa para validar la firma electrónica.']]);
        }

        // Rejections are recorded and thrown here, before anything of the company changes.
        $accepted = $certificate === null ? null : $this->certificates->accept($company, $certificate, (string) $password, (string) $ruc, $actor);

        try {
            return DB::transaction(function () use ($company, $data, $ruc, $accepted, $actor, $previousRuc, $rucChanged): Company {
                $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->assertRucAvailable($company, $ruc);
                if ($rucChanged && $previousRuc !== null) {
                    $this->assertNoDocumentsInProcess($company);
                }

                $before = $this->snapshot($company);
                foreach (self::COLUMNS as $input => $column) {
                    if (array_key_exists($input, $data)) {
                        $company->{$column} = $input === 'email' && $data[$input] !== null ? mb_strtolower($data[$input]) : $data[$input];
                    }
                }
                $company->save();
                $activated = $accepted instanceof AcceptedCertificate ? $this->certificates->activate($company, $accepted, $actor) : null;
                // A new RUC is another taxpayer: electronic invoicing is switched on again after review.
                if ($rucChanged) {
                    $company->fiscalSettings()->update(['sri_submission_enabled' => false]);
                }

                $this->audit->record($actor, $company, $rucChanged && $previousRuc !== null ? 'company.ruc_changed' : 'company.fiscal_identity_saved', $company, $before, [
                    ...$this->snapshot($company),
                    'previousRuc' => $rucChanged ? $previousRuc : null,
                    'certificatePublicId' => $activated?->public_id,
                ]);

                return $company->refresh();
            });
        } catch (Throwable $exception) {
            if ($accepted instanceof AcceptedCertificate) {
                $this->certificates->discard($accepted);
            }
            throw $exception;
        }
    }

    private function assertRucAvailable(Company $company, ?string $ruc): void
    {
        if ($ruc !== null && Company::query()->where('ruc', $ruc)->where('id', '<>', $company->id)->exists()) {
            throw ValidationException::withMessages(['ruc' => ['El RUC ya está registrado en otra empresa FACSTO.']]);
        }
    }

    /** Documents still in process need the previous RUC and its signature to finish or be corrected. */
    private function assertNoDocumentsInProcess(Company $company): void
    {
        $pending = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->where('status', '<>', DocumentStatus::AUTHORIZED->value)
            ->count();
        if ($pending > 0) {
            throw new ApiException(ApiErrorCode::COMPANY_RUC_CHANGE_BLOCKED, "No se puede cambiar el RUC: hay {$pending} comprobante(s) del RUC actual que todavía no están autorizados por el SRI. Termínalos o corrígelos primero.", 409, ['pendingDocuments' => $pending]);
        }
    }

    /** @return array<string, string|null> */
    private function snapshot(Company $company): array
    {
        return [
            'ruc' => $company->ruc,
            'legalName' => $company->legal_name,
            'commercialName' => $company->commercial_name,
            'mainAddress' => $company->main_address,
            'email' => $company->email,
            'phone' => $company->phone,
        ];
    }
}
