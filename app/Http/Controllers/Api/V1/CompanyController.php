<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\DeleteCompanyLogoAction;
use App\Domains\Companies\Actions\UpdateCompanyAction;
use App\Domains\Companies\Actions\UpdateCompanyLogoAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Http\Requests\Companies\UploadCompanyLogoRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CompanyController extends Controller
{
    public function show(Request $request, CompanyContext $context): CompanyResource
    {
        $company = $context->company();
        $this->authorize('view', $company);

        return $this->resource($company, $request);
    }

    public function update(UpdateCompanyRequest $request, CompanyContext $context, UpdateCompanyAction $update): CompanyResource
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $data = $request->validated();
        $attributes = [];

        foreach ([
            'legalName' => 'legal_name',
            'commercialName' => 'commercial_name',
            'mainAddress' => 'main_address',
            'email' => 'email',
            'phone' => 'phone',
        ] as $input => $attribute) {
            if (array_key_exists($input, $data)) {
                $attributes[$attribute] = $data[$input];
            }
        }

        return $this->resource($update->execute($company, $attributes), $request);
    }

    public function logo(CompanyContext $context): StreamedResponse
    {
        $company = $context->company();
        $this->authorize('view', $company);

        abort_unless($company->logo_path && Storage::disk('private')->exists($company->logo_path), 404);

        return Storage::disk('private')->response($company->logo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateLogo(UploadCompanyLogoRequest $request, CompanyContext $context, UpdateCompanyLogoAction $update): CompanyResource
    {
        $company = $context->company();
        $this->authorize('update', $company);

        return $this->resource($update->execute($company, $request->file('logo'), $request->user()), $request);
    }

    public function deleteLogo(Request $request, CompanyContext $context, DeleteCompanyLogoAction $delete): CompanyResource
    {
        $company = $context->company();
        $this->authorize('update', $company);

        return $this->resource($delete->execute($company, $request->user()), $request);
    }

    private function resource(Company $company, Request $request): CompanyResource
    {
        return new CompanyResource($company->load([
            'memberships' => fn ($query) => $query->where('user_id', $request->user()->id),
            'fiscalSettings',
        ]));
    }
}
