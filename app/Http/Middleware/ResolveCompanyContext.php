<?php

namespace App\Http\Middleware;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Companies\Models\Company;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each user belongs to exactly one company (one subscription = one company),
 * so the tenant is always derived on the server from the authenticated user.
 * Clients never choose the company.
 */
final class ResolveCompanyContext
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('is_active', true))
            ->first();

        if (! $company) {
            throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'Tu usuario no tiene acceso activo a ninguna empresa.', 403);
        }

        if ($company->status !== CompanyStatus::ACTIVE) {
            throw new ApiException(ApiErrorCode::COMPANY_INACTIVE, 'La empresa está inactiva.', 403);
        }

        $this->companyContext->set($company);

        return $next($request);
    }
}
