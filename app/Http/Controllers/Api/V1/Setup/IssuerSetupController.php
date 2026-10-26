<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Actions\CompleteOnboardingAction;
use App\Domains\Companies\Fiscal\Actions\ConfigureIssuerAction;
use App\Domains\Companies\Fiscal\DTOs\IssuerConfigurationData;
use App\Domains\Companies\Fiscal\Services\CompanyOnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\ConfigureIssuerRequest;
use App\Http\Resources\Api\V1\CompanySetupStatusResource;

final class IssuerSetupController extends Controller
{
    public function update(
        ConfigureIssuerRequest $request,
        CompanyContext $context,
        ConfigureIssuerAction $configure,
        CompleteOnboardingAction $completeOnboarding,
        CompanyOnboardingService $onboarding,
    ): CompanySetupStatusResource {
        $company = $context->company();
        $this->authorize('update', $company);
        $data = $request->validated();

        $updatedCompany = $configure->execute(new IssuerConfigurationData(
            $data['ruc'],
            trim($data['legalName']),
            isset($data['commercialName']) ? trim($data['commercialName']) : null,
            trim($data['mainAddress']),
            $data['email'] ?? null,
            $data['phone'] ?? null,
        ), $request->user());

        $completeOnboarding->completeIfReady($updatedCompany, $request->user());

        return new CompanySetupStatusResource($onboarding->status($updatedCompany, $request->user()));
    }
}
