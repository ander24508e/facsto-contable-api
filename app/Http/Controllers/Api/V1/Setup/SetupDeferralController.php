<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Actions\SetCompanySetupDeferralAction;
use App\Domains\Companies\Fiscal\Services\CompanyFiscalReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanySetupStatusResource;

final class SetupDeferralController extends Controller
{
    public function defer(
        CompanyContext $context,
        SetCompanySetupDeferralAction $setDeferral,
        CompanyFiscalReadinessService $readiness,
    ): CompanySetupStatusResource {
        $this->authorize('update', $context->company());
        $company = $setDeferral->execute(true, request()->user());

        return new CompanySetupStatusResource($readiness->summary($company));
    }

    public function resume(
        CompanyContext $context,
        SetCompanySetupDeferralAction $setDeferral,
        CompanyFiscalReadinessService $readiness,
    ): CompanySetupStatusResource {
        $this->authorize('update', $context->company());
        $company = $setDeferral->execute(false, request()->user());

        return new CompanySetupStatusResource($readiness->summary($company));
    }
}
