<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Services\CompanyFiscalReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanySetupStatusResource;

final class SetupStatusController extends Controller
{
    public function __invoke(CompanyContext $context, CompanyFiscalReadinessService $readiness): CompanySetupStatusResource
    {
        return new CompanySetupStatusResource($readiness->summary($context->company()));
    }
}
