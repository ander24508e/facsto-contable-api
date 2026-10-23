<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\DTOs\EmissionStructureData;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class ConfigureEmissionStructureAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(EmissionStructureData $data, User $actor): Company
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $data, $actor): Company {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            $establishment = $company->establishments()->firstOrNew(['code' => $data->establishmentCode]);
            $previousEstablishment = $company->establishments()->where('is_main', true)->first();
            if ($previousEstablishment && $previousEstablishment->isNot($establishment)) {
                $previousEstablishment->forceFill(['is_main' => false])->save();
            }

            $establishment->fill([
                'name' => $data->establishmentName,
                'address' => $data->establishmentAddress,
                'is_active' => true,
                'is_main' => true,
            ])->save();

            $emissionPoint = $establishment->emissionPoints()->firstOrNew(['code' => $data->emissionPointCode]);
            $previousDefault = $establishment->emissionPoints()->where('is_default', true)->first();
            if ($previousDefault && $previousDefault->isNot($emissionPoint)) {
                $previousDefault->forceFill(['is_default' => false])->save();
            }

            $emissionPoint->fill([
                'company_id' => $company->id,
                'name' => $data->emissionPointName,
                'is_active' => true,
                'is_default' => true,
            ])->save();

            $settings = $company->fiscalSettings()->firstOrNew();
            $oldSettings = [
                'environment' => $settings->sri_environment?->value,
                'emissionType' => $settings->emission_type?->value,
            ];
            $settings->fill([
                'sri_environment' => $data->environment,
                'emission_type' => $data->emissionType,
            ])->save();

            $this->audit->record($actor, $company, 'company.emission_configured', $company, $oldSettings, [
                'environment' => $data->environment->value,
                'emissionType' => $data->emissionType->value,
                'establishmentCode' => $establishment->code,
                'emissionPointCode' => $emissionPoint->code,
            ]);

            return $company->refresh();
        });
    }
}
