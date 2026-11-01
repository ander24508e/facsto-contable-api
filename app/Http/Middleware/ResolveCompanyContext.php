<?php

namespace App\Http\Middleware;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Services\CompanySettings;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each user belongs to exactly one company (one subscription = one company),
 * so the tenant is always derived on the server from the authenticated user.
 * Clients never choose the company.
 */
final class ResolveCompanyContext
{
    public function __construct(private readonly CompanyContext $companyContext, private readonly CompanySettings $settings) {}

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
        $this->guardIdleSession($request, $company);

        return $next($request);
    }

    /**
     * Sistema → «cierre por inactividad»: a browser session unused for longer than the company allows is
     * closed here too, so the rule does not depend only on the screen. Token clients have no session.
     */
    private function guardIdleSession(Request $request, Company $company): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $session = $request->session();
        $minutes = $this->settings->for($company)->idle_timeout_minutes;
        $last = $session->get('facsto.last_activity_at');
        if ($minutes > 0 && is_int($last) && now()->getTimestamp() - $last > $minutes * 60) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            throw new ApiException(ApiErrorCode::SESSION_IDLE_TIMEOUT, 'Tu sesión se cerró por inactividad.', 401);
        }
        $session->put('facsto.last_activity_at', now()->getTimestamp());
    }
}
