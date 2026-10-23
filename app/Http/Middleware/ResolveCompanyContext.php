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

final class ResolveCompanyContext
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $publicId = $request->header('X-Facsto-Company');

        if (! is_string($publicId) || ! preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            throw new ApiException(ApiErrorCode::INVALID_COMPANY_CONTEXT, 'Envía una empresa activa mediante X-Facsto-Company.', 422);
        }

        $company = Company::query()->where('public_id', $publicId)->first();

        if (! $company) {
            throw new ApiException(ApiErrorCode::COMPANY_NOT_FOUND, 'La empresa indicada no existe.', 404);
        }

        if ($company->status !== CompanyStatus::ACTIVE) {
            throw new ApiException(ApiErrorCode::COMPANY_INACTIVE, 'La empresa indicada está inactiva.', 403);
        }

        $isMember = $company->memberships()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->exists();

        if (! $isMember) {
            throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'El usuario no tiene acceso a la empresa indicada.', 403);
        }

        $this->companyContext->set($company);

        return $next($request);
    }
}
