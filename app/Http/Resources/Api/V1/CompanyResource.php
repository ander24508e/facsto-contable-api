<?php

namespace App\Http\Resources\Api\V1;

use App\Domains\Companies\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;
        $membership = $company->memberships->firstWhere('user_id', $request->user()->id);

        return [
            'publicId' => $company->public_id,
            'ruc' => $company->ruc,
            'legalName' => $company->legal_name,
            'commercialName' => $company->commercial_name,
            'mainAddress' => $company->main_address,
            'email' => $company->email,
            'phone' => $company->phone,
            'accountingRequired' => $company->fiscalSettings?->accounting_required ?? false,
            'status' => $company->status->value,
            'role' => $membership?->role->value,
        ];
    }
}
