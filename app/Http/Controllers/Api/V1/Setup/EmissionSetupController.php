<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Actions\ConfigureEmissionStructureAction;
use App\Domains\Companies\Fiscal\DTOs\EmissionStructureData;
use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Companies\Fiscal\Services\CompanyFiscalReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\ConfigureEmissionStructureRequest;
use App\Http\Resources\Api\V1\CompanySetupStatusResource;

final class EmissionSetupController extends Controller
{
    public function update(ConfigureEmissionStructureRequest $request, CompanyContext $context, ConfigureEmissionStructureAction $configure, CompanyFiscalReadinessService $readiness): CompanySetupStatusResource
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $data = $request->validated();

        $configure->execute(new EmissionStructureData(
            $data['establishmentCode'],
            trim($data['establishmentName']),
            trim($data['establishmentAddress']),
            $data['emissionPointCode'],
            trim($data['emissionPointName']),
            SriEnvironment::from($data['environment'] ?? SriEnvironment::TEST->value),
            EmissionType::from($data['emissionType'] ?? EmissionType::NORMAL->value),
        ), $request->user());

        return new CompanySetupStatusResource($readiness->summary($context->company()));
    }
}
