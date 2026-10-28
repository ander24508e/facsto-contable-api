<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Actions\UpdateFiscalProfileAction;
use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\Fiscal\UpdateFiscalProfileRequest;
use App\Http\Resources\Api\V1\FiscalProfileResource;

final class FiscalProfileController extends Controller
{
    public function show(CompanyContext $context): FiscalProfileResource
    {
        $company = $context->company();
        $this->authorize('view', $company);

        return new FiscalProfileResource($company->fiscalSettings ?? new CompanyFiscalSettings([
            'accounting_required' => false,
        ]));
    }

    public function update(UpdateFiscalProfileRequest $request, CompanyContext $context, UpdateFiscalProfileAction $update): FiscalProfileResource
    {
        $this->authorize('update', $context->company());

        $attributes = [];
        foreach ([
            'accountingRequired',
            'specialTaxpayerNumber',
            'withholdingAgentNumber',
            'artisanQualificationNumber',
            'rimpeType',
        ] as $key) {
            if (array_key_exists($key, $request->validated())) {
                $attributes[$key] = $request->validated()[$key];
            }
        }

        return new FiscalProfileResource($update->execute($attributes, $request->user()));
    }
}
