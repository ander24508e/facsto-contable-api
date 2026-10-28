<?php

namespace App\Domains\Certificates\Actions;

use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Certificates\Services\CertificateAcceptanceService;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Onboarding upload: the certificate must belong to the RUC already configured for the company. */
final class UploadDigitalCertificateAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CertificateAcceptanceService $certificates,
    ) {}

    public function execute(UploadedFile $file, #[\SensitiveParameter] string $password, User $actor): DigitalCertificate
    {
        $company = $this->context->company();
        $accepted = $this->certificates->accept($company, $file, $password, (string) $company->ruc, $actor);

        try {
            return DB::transaction(function () use ($company, $accepted, $actor): DigitalCertificate {
                $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

                return $this->certificates->activate($company, $accepted, $actor);
            });
        } catch (Throwable $exception) {
            $this->certificates->discard($accepted);
            throw $exception;
        }
    }
}
