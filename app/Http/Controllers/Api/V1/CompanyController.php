<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Actions\UpdateCompanyAction;
use App\Domains\Companies\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CompanyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $companies = Company::query()
            ->where('status', 'ACTIVE')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', auth()->id())->where('is_active', true))
            ->with(['memberships' => fn ($query) => $query->where('user_id', auth()->id())->where('is_active', true)])
            ->orderBy('legal_name')
            ->get();

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request, CreateCompanyAction $create): CompanyResource
    {
        $data = $request->validated();
        $company = $create->execute($request->user(), [
            'ruc' => $data['ruc'] ?? null,
            'legal_name' => $data['legalName'] ?? null,
            'commercial_name' => $data['commercialName'] ?? null,
            'main_address' => $data['mainAddress'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        return new CompanyResource($company);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        return new CompanyResource($company->load(['memberships' => fn ($query) => $query->where('user_id', auth()->id())]));
    }

    public function update(UpdateCompanyRequest $request, Company $company, UpdateCompanyAction $update): CompanyResource
    {
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

        return new CompanyResource($update->execute($company, $attributes)->load(['memberships' => fn ($query) => $query->where('user_id', auth()->id())]));
    }
}
