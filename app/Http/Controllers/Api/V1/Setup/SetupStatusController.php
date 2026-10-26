<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Services\CompanyOnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanySetupStatusResource;
use Illuminate\Http\Request;

final class SetupStatusController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context, CompanyOnboardingService $onboarding): CompanySetupStatusResource
    {
        return new CompanySetupStatusResource($onboarding->status($context->company(), $request->user()));
    }
}
