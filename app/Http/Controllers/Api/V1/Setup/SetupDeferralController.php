<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Actions\CompleteOnboardingAction;
use App\Domains\Companies\Fiscal\Services\CompanyOnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanySetupStatusResource;
use Illuminate\Http\Request;

final class SetupDeferralController extends Controller
{
    public function defer(
        Request $request,
        CompanyContext $context,
        CompleteOnboardingAction $completeOnboarding,
        CompanyOnboardingService $onboarding,
    ): CompanySetupStatusResource {
        $company = $context->company();
        $this->authorize('update', $company);
        $completeOnboarding->skip($company, $request->user());

        return new CompanySetupStatusResource($onboarding->status($company, $request->user()));
    }
}
