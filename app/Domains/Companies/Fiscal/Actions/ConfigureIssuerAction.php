<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\DTOs\IssuerConfigurationData;
use App\Domains\Companies\Fiscal\Services\EcuadorTaxIdentifierValidator;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class ConfigureIssuerAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly EcuadorTaxIdentifierValidator $rucValidator,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(IssuerConfigurationData $data, User $actor): Company
    {
        $company = $this->context->company();
        if (! $this->rucValidator->isValidRuc($data->ruc)) {
            throw new ApiException(ApiErrorCode::INVALID_ECUADOR_RUC, 'El RUC no tiene una estructura ecuatoriana válida.', 422);
        }

        return DB::transaction(function () use ($company, $data, $actor): Company {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            if (Company::query()->where('ruc', $data->ruc)->where('id', '<>', $company->id)->exists()) {
                throw new ApiException(ApiErrorCode::COMPANY_RUC_ALREADY_REGISTERED, 'El RUC ya está registrado en otra empresa FACSTO.', 409);
            }

            $before = ['ruc' => $company->ruc, 'legalName' => $company->legal_name, 'mainAddress' => $company->main_address];
            $company->fill([
                'ruc' => $data->ruc,
                'legal_name' => $data->legalName,
                'commercial_name' => $data->commercialName,
                'main_address' => $data->mainAddress,
                'email' => $data->email,
                'phone' => $data->phone,
            ])->save();

            $this->audit->record($actor, $company, 'company.issuer_configured', $company, $before, [
                'ruc' => $company->ruc,
                'legalName' => $company->legal_name,
                'mainAddress' => $company->main_address,
            ]);

            return $company->refresh();
        });
    }
}
